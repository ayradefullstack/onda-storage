<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\Auth\LoginLeaseLostException;
use App\Exceptions\Auth\LoginLockedException;
use App\Exceptions\Auth\LoginLockoutBusyException;
use App\Models\Auth\LoginLockout;
use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Failed-password lockout for the scope (normalized email + client IP).
 *
 * `run()` wraps ONE password verification:
 *
 *   1. take a per-scope mutex (bounded wait, fail closed);
 *   2. under it, drop expired state, refuse if the scope is locked — WITHOUT
 *      calling $verify, so a locked scope never checks a password, never
 *      counts and never moves `locked_until`;
 *   3. call $verify. A ValidationException on the username key IS a failed
 *      password verification: it is counted, then re-thrown (or replaced by
 *      LoginLockedException when it reaches the threshold). Anything else
 *      passes through untouched and uncounted;
 *   4. a normal return is a verified password: the state is deleted.
 *
 * The mutex is what stops N concurrent attempts from all passing the gate
 * before any of their password checks has finished; the atomic SQL writes
 * keep the counts right even if the mutex TTL ever lapsed mid-request.
 *
 * No DB transaction is opened here or around the login route, so the written
 * state survives the ValidationException that ends a failed request.
 *
 * Time: every instant is `now()->getTimestamp()` (one clock, so tests drive
 * it with Carbon). Expiry rules: a scope is locked while `locked_until > now`;
 * an expired lock or window is deleted on next sight, so every failure takes
 * the same create-or-increment path.
 *
 * The scope id is an HMAC under the app key: opaque, no raw email or IP
 * stored. Rotating APP_KEY therefore resets every lockout.
 */
class LoginLockoutService
{
    /**
     * @template T
     *
     * @param  Closure(): T  $verify  verifies the password; throws a ValidationException on the username key when it is wrong
     * @return T
     *
     * @throws LoginLockedException
     * @throws LoginLockoutBusyException
     * @throws LoginLeaseLostException the password was verified but the lease was lost, or admission could not be confirmed
     */
    public function run(string $email, ?string $ip, Closure $verify): mixed
    {
        $scope = $this->scopeHash($email, $ip);
        $lock = $this->acquire($scope);
        $acquiredAt = hrtime(true);

        try {
            $state = $this->currentState($scope);

            if ($state !== null && $state->locked_until !== null) {
                throw new LoginLockedException($state->locked_until, now()->getTimestamp());
            }

            try {
                $result = $verify();
            } catch (ValidationException $e) {
                if (! array_key_exists(Fortify::username(), $e->errors())) {
                    throw $e;
                }

                $lockedUntil = $this->recordFailure($scope);

                if ($lockedUntil !== null) {
                    throw new LoginLockedException($lockedUntil, now()->getTimestamp());
                }

                throw $e;
            }

            // The password was verified. Only the CURRENT owner of the mutex may
            // erase state or let the login stand: refresh() is one atomic
            // "owner = me AND not expired" update that also renews the lease for
            // the few milliseconds the delete needs. If it fails, the lease ran
            // out while this request was working and another request may have
            // written newer state: touch nothing and let the caller undo the
            // login (fail closed).
            if (! $this->renewLease($lock, $acquiredAt) || $this->lockedBehindOurBack($scope)) {
                throw new LoginLeaseLostException;
            }

            $this->clear($state);

            return $result;
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Opaque scope id. The context prefix keeps it distinct from any other
     * HMAC made with the app key.
     *
     * The email is folded to a conservative ASCII form. That matters: the
     * account lookup is a plain `where email = ?` under MySQL's
     * accent-insensitive, ignorable-character collation
     * (utf8mb4_unicode_ci), so `usér@x.com`, `user@x.com<NBSP>` or an address
     * with a soft hyphen inside all log in as `user@x.com`. A scope keyed on
     * the raw spelling would let an attacker rotate such variants to dodge
     * the lockout for one account. Steps: lower-case, transliterate to ASCII
     * (characters with no mapping are dropped), then keep only printable,
     * non-space ASCII (and never `|`, the field separator below).
     *
     * Deliberately account-independent (no database lookup), so a known and
     * an unknown email fold identically and the lockout is no oracle for
     * account existence. It may MERGE spellings that the database would not
     * match; that only makes the scope coarser, and the scope also contains
     * the caller's own IP. Valid registered emails are plain ASCII and are
     * unchanged by it.
     */
    public function scopeHash(string $email, ?string $ip): string
    {
        $folded = Str::transliterate(Str::lower(trim($email)), '');
        $folded = (string) preg_replace('/[^\x21-\x7B\x7D-\x7E]/', '', $folded);

        return hash_hmac(
            'sha256',
            'login-lockout|'.$folded.'|'.($ip ?? ''),
            (string) config('app.key'),
        );
    }

    /**
     * Mutex for the scope. Timeout, a store error or a store without lock
     * support all mean "busy": $verify must not run without it.
     */
    private function acquire(string $scope): Lock
    {
        $ttl = max(5, (int) config('auth-security.lockout.lock_ttl_seconds'));
        $wait = max(0, (int) config('auth-security.lockout.lock_wait_seconds'));
        $lock = null;

        try {
            $store = Cache::store((string) config('auth-security.lockout.lock_store'))->getStore();

            if (! $store instanceof LockProvider) {
                throw new RuntimeException('The configured lockout lock store does not support locks.');
            }

            $lock = $store->lock('login-lockout:'.$scope, $ttl);

            if (! $lock instanceof Lock) {
                throw new RuntimeException('The lockout lock does not support lease renewal.');
            }

            $acquired = $wait > 0 ? $lock->block($wait) : $lock->get();
        } catch (LockTimeoutException) {
            throw new LoginLockoutBusyException;
        } catch (Throwable $e) {
            report($e);

            // The store may have granted the lock and then failed (e.g. while
            // pruning expired locks): do not leave the scope busy until the TTL.
            if ($lock !== null) {
                $this->release($lock);
            }

            throw new LoginLockoutBusyException;
        }

        if (! $acquired) {
            throw new LoginLockoutBusyException;
        }

        return $lock;
    }

    /**
     * Still the owner? refresh() is one atomic "owner = me AND not expired"
     * update. The elapsed-time check is the margin for clock drift between web
     * hosts (the lease's expiry is written with each host's own clock).
     */
    private function renewLease(Lock $lock, int $acquiredAt): bool
    {
        $ttl = max(5, (int) config('auth-security.lockout.lock_ttl_seconds'));

        if ((hrtime(true) - $acquiredAt) / 1e9 >= max(1, $ttl - 5)) {
            return false;
        }

        try {
            return (bool) $lock->refresh();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * A lock that was established while this request was verifying means a
     * newer decision exists: do not let a login stand against it.
     */
    private function lockedBehindOurBack(string $scope): bool
    {
        try {
            $lockedUntil = LoginLockout::query()->where('scope_hash', $scope)->value('locked_until');
        } catch (QueryException|PDOException|LostConnectionException $e) {
            // Storage failed: admission cannot be confirmed, so fail closed
            // (true makes run() throw LoginLeaseLostException). Any other
            // Throwable propagates untouched.
            report($e);

            return true;
        }

        return is_numeric($lockedUntil) && (int) $lockedUntil > now()->getTimestamp();
    }

    private function release(LockContract $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $e) {
            // Never mask the login outcome; the lock expires by itself.
            report($e);
        }
    }

    /**
     * The scope's live state, or null. Expired state (lock over, or window
     * over without a lock) is deleted here.
     */
    private function currentState(string $scope): ?LoginLockout
    {
        $state = LoginLockout::query()->where('scope_hash', $scope)->first();

        if ($state === null) {
            return null;
        }

        $now = now()->getTimestamp();

        $expired = $state->locked_until !== null
            ? $state->locked_until <= $now
            : $state->window_started_at + $this->windowSeconds() <= $now;

        if ($expired) {
            LoginLockout::query()->whereKey($state->getKey())->delete();

            return null;
        }

        // Self-heal: a request that died between counting the threshold
        // failure and setting the lock leaves count >= max with no lock. Set
        // it now, so that scope never gets an extra free password check.
        if ($state->locked_until === null && $state->failed_count >= $this->maxFailures()) {
            $state->locked_until = $this->establishLock($state, $now);
        }

        return $state;
    }

    /**
     * Set `locked_until` once (conditional on it still being null) and return
     * the value in force: ours, or the one a concurrent writer set first.
     */
    private function establishLock(LoginLockout $state, int $now): int
    {
        $lockedUntil = $now + $this->lockoutSeconds();

        $established = LoginLockout::query()
            ->whereKey($state->getKey())
            ->whereNull('locked_until')
            ->update(['locked_until' => $lockedUntil]);

        if ($established === 1) {
            return $lockedUntil;
        }

        $existing = LoginLockout::query()->whereKey($state->getKey())->value('locked_until');

        return is_numeric($existing) ? (int) $existing : $lockedUntil;
    }

    /**
     * Count one failed verification. Returns `locked_until` when the scope is
     * (now) locked, null otherwise.
     *
     * Increment first, create if there was no row, retry on a unique-key race:
     * a row deleted by the pruner between the gate and here cannot swallow a
     * failure. The lock decision is made from the row as re-read AFTER the
     * write, and `locked_until` is set once, conditionally.
     */
    private function recordFailure(string $scope): ?int
    {
        $now = now()->getTimestamp();
        $windowCutoff = $now - $this->windowSeconds();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            // Only inside the CURRENT window (or under a running lock): a slow
            // password check that straddled the window's end must not pile onto
            // the old count.
            if (LoginLockout::query()
                ->where('scope_hash', $scope)
                ->where(fn ($q) => $q->where('locked_until', '>', $now)
                    ->orWhere(fn ($q) => $q->whereNull('locked_until')->where('window_started_at', '>', $windowCutoff)))
                ->increment('failed_count') > 0) {
                break;
            }

            // Dead row (lock over, or window over without a lock): make room for a new one.
            LoginLockout::query()
                ->where('scope_hash', $scope)
                ->where(fn ($q) => $q->where('locked_until', '<=', $now)
                    ->orWhere(fn ($q) => $q->whereNull('locked_until')->where('window_started_at', '<=', $windowCutoff)))
                ->delete();

            try {
                LoginLockout::query()->create([
                    'scope_hash' => $scope,
                    'failed_count' => 1,
                    'window_started_at' => $now,
                    'locked_until' => null,
                ]);

                break;
            } catch (UniqueConstraintViolationException) {
                // Another writer created it first: increment it next round.
            }
        }

        $state = LoginLockout::query()->where('scope_hash', $scope)->first();

        if ($state === null) {
            // Cannot prove the failure was recorded: fail closed.
            throw new RuntimeException('Failed-login state could not be recorded.');
        }

        if ($state->locked_until !== null) {
            return $state->locked_until > $now ? $state->locked_until : null;
        }

        if ($state->failed_count < $this->maxFailures()) {
            return null;
        }

        $lockedUntil = $this->establishLock($state, $now);

        return $lockedUntil > $now ? $lockedUntil : null;
    }

    /**
     * Delete the state this request SAW at the gate, and only if it is still
     * exactly that (same row, same count, no lock). Anything a concurrent
     * request wrote since stays. With no row at the gate there is nothing to
     * clear. A failure to clear must not turn a verified (already logged-in)
     * request into an error; the stale state just ages out.
     */
    private function clear(?LoginLockout $seen): void
    {
        if ($seen === null) {
            return;
        }

        try {
            LoginLockout::query()
                ->whereKey($seen->getKey())
                ->where('failed_count', $seen->failed_count)
                ->whereNull('locked_until')
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function maxFailures(): int
    {
        return max(1, (int) config('auth-security.login.max_failures'));
    }

    private function windowSeconds(): int
    {
        return max(1, (int) config('auth-security.login.failure_window_seconds'));
    }

    private function lockoutSeconds(): int
    {
        return max(1, (int) config('auth-security.login.lockout_seconds'));
    }
}
