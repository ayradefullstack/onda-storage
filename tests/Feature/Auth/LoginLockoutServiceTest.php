<?php

declare(strict_types=1);

use App\Exceptions\Auth\LoginLeaseLostException;
use App\Exceptions\Auth\LoginLockedException;
use App\Exceptions\Auth\LoginLockoutBusyException;
use App\Models\Auth\LoginLockout;
use App\Services\Auth\LoginLockoutService;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

/**
 * The lockout service on its own: synchronization, fail-closed behavior,
 * races, state persistence and cleanup.
 *
 * Honest scope: these run on SQLite :memory:, in one process. They prove the
 * LOGIC of the mutex and of the race handling (a second run() started while
 * the first holds the scope, a row created behind our back, a row deleted
 * between the gate and the write). They do NOT prove MySQL row/lock behavior
 * under real parallel requests — see the Phase 3 report.
 */
const SVC_EMAIL = 'svc@example.com';
const SVC_IP = '198.51.100.10';

function svc(): LoginLockoutService
{
    return app(LoginLockoutService::class);
}

function svcRow(string $email = SVC_EMAIL, string $ip = SVC_IP): ?LoginLockout
{
    return LoginLockout::query()->where('scope_hash', svc()->scopeHash($email, $ip))->first();
}

function svcWrongPassword(): ValidationException
{
    return ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
}

beforeEach(function () {
    $this->freezeTime();
});

// ----------------------------------------------------------------- mutex

test('a second attempt on a scope that is mid-verification fails closed and never verifies', function () {
    $innerVerified = false;

    $result = svc()->run(SVC_EMAIL, SVC_IP, function () use (&$innerVerified) {
        config(['auth-security.lockout.lock_wait_seconds' => 0]);

        // A concurrent request for the SAME scope, arriving before the first
        // password check has finished.
        expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, function () use (&$innerVerified) {
            $innerVerified = true;
        }))->toThrow(LoginLockoutBusyException::class);

        return 'outer-ok';
    });

    expect($result)->toBe('outer-ok');
    expect($innerVerified)->toBeFalse();
});

test('a different scope is not blocked by the mutex of another', function () {
    config(['auth-security.lockout.lock_wait_seconds' => 0]);

    $result = svc()->run(SVC_EMAIL, SVC_IP, fn () => svc()->run('other@example.com', SVC_IP, fn () => 'inner-ok'));

    expect($result)->toBe('inner-ok');
});

test('the mutex is released after success, after a failure and after an unexpected exception', function () {
    config(['auth-security.lockout.lock_wait_seconds' => 0]);

    svc()->run(SVC_EMAIL, SVC_IP, fn () => 'ok');
    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, fn () => throw svcWrongPassword()))->toThrow(ValidationException::class);
    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);

    expect(svc()->run(SVC_EMAIL, SVC_IP, fn () => 'still-ok'))->toBe('still-ok');
});

test('a mutex held by someone else makes the attempt fail closed after the bounded wait, without sleeping', function () {
    Sleep::fake(syncWithCarbon: true);
    config(['auth-security.lockout.lock_wait_seconds' => 2]);

    $held = Cache::store('database')->lock('login-lockout:'.svc()->scopeHash(SVC_EMAIL, SVC_IP), 30);
    expect($held->get())->toBeTrue();

    $verified = false;
    $started = now()->getTimestamp();

    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, function () use (&$verified) {
        $verified = true;
    }))->toThrow(LoginLockoutBusyException::class);

    expect($verified)->toBeFalse();
    expect(now()->getTimestamp() - $started)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3);
    expect(LoginLockout::count())->toBe(0);

    $held->release();
});

test('a lock store that cannot lock, or does not exist, fails closed and never verifies', function (string $store) {
    Exceptions::fake();

    // A cache store that cannot lock (it is not a LockProvider).
    Cache::extend('nolock', fn () => new Repository(Mockery::mock(Store::class)));
    config(['cache.stores.nolock' => ['driver' => 'nolock'], 'auth-security.lockout.lock_store' => $store]);

    $verified = false;

    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, function () use (&$verified) {
        $verified = true;
    }))->toThrow(LoginLockoutBusyException::class);

    expect($verified)->toBeFalse();
})->with(['store without lock support' => 'nolock', 'unknown store' => 'no-such-store']);

// ------------------------------------------------------------------ races

test('a row created behind our back (unique-key race) is incremented, not lost', function () {
    $scope = svc()->scopeHash(SVC_EMAIL, SVC_IP);

    // Between "no row" and our INSERT, another writer creates the scope's row.
    LoginLockout::creating(function () use ($scope) {
        DB::table('login_lockouts')->insert([
            'scope_hash' => $scope,
            'failed_count' => 1,
            'window_started_at' => now()->getTimestamp(),
        ]);
    });

    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, fn () => throw svcWrongPassword()))->toThrow(ValidationException::class);

    expect(LoginLockout::count())->toBe(1);
    expect(svcRow()->failed_count)->toBe(2); // the racing writer's 1 + ours
});

test('a row deleted between the gate and the write does not swallow the failure', function () {
    LoginLockout::create([
        'scope_hash' => svc()->scopeHash(SVC_EMAIL, SVC_IP),
        'failed_count' => 2,
        'window_started_at' => now()->getTimestamp(),
    ]);

    // The pruner runs while the password is being verified.
    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, function () {
        DB::table('login_lockouts')->delete();

        throw svcWrongPassword();
    }))->toThrow(ValidationException::class);

    expect(svcRow()->failed_count)->toBe(1);
});

test('a lock set by a concurrent writer is reported as it is, never recomputed', function () {
    $scope = svc()->scopeHash(SVC_EMAIL, SVC_IP);
    $existing = now()->getTimestamp() + 5;
    LoginLockout::create(['scope_hash' => $scope, 'failed_count' => 2, 'window_started_at' => now()->getTimestamp()]);

    try {
        svc()->run(SVC_EMAIL, SVC_IP, function () use ($scope, $existing) {
            // The lock lands while this request verifies; ours is then the 3rd failure.
            DB::table('login_lockouts')->where('scope_hash', $scope)->update(['locked_until' => $existing]);

            throw svcWrongPassword();
        });

        $this->fail('Expected a LoginLockedException.');
    } catch (LoginLockedException $e) {
        expect($e->lockedUntil)->toBe($existing);
    }

    expect(svcRow()->locked_until)->toBe($existing);
});

test('the lock is established once: the threshold sets locked_until, nothing later moves it', function () {
    config(['auth-security.login.max_failures' => 2]);
    $start = now()->getTimestamp();

    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, fn () => throw svcWrongPassword()))->toThrow(ValidationException::class);
    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, fn () => throw svcWrongPassword()))->toThrow(LoginLockedException::class);
    expect(svcRow()->locked_until)->toBe($start + 1800);

    $this->travel(600)->seconds();
    $verified = false;
    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, function () use (&$verified) {
        $verified = true;
    }))->toThrow(LoginLockedException::class);

    expect($verified)->toBeFalse();
    expect(svcRow()->locked_until)->toBe($start + 1800);
    expect(svcRow()->failed_count)->toBe(2);
});

// --------------------------------------------- what counts as a failure

test('only a ValidationException on the username key counts', function () {
    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, fn () => throw ValidationException::withMessages(['password' => 'x'])))
        ->toThrow(ValidationException::class);
    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, fn () => throw new RuntimeException('not a credentials error')))
        ->toThrow(RuntimeException::class);

    expect(LoginLockout::count())->toBe(0);
});

test('a verified password returns the result and deletes the state', function () {
    LoginLockout::create([
        'scope_hash' => svc()->scopeHash(SVC_EMAIL, SVC_IP),
        'failed_count' => 2,
        'window_started_at' => now()->getTimestamp(),
    ]);

    expect(svc()->run(SVC_EMAIL, SVC_IP, fn () => 'verified'))->toBe('verified');
    expect(svcRow())->toBeNull();
});

test('the failure survives the exception that ends the request', function () {
    // The caller sees the ValidationException; the row is already written
    // (no transaction was opened that the exception could roll back).
    $levelBefore = DB::transactionLevel();

    try {
        svc()->run(SVC_EMAIL, SVC_IP, fn () => throw svcWrongPassword());
    } catch (ValidationException) {
        // expected
    }

    expect(DB::transactionLevel())->toBe($levelBefore);
    expect(svcRow()->failed_count)->toBe(1);
});

// ------------------------------------------------------------- scope hash

test('the scope id is opaque, stable and normalized', function () {
    $hash = svc()->scopeHash('User@Example.com', '203.0.113.5');

    expect($hash)->toMatch('/^[0-9a-f]{64}$/');
    expect($hash)->not->toContain('example')->not->toContain('203.0.113.5');
    expect(svc()->scopeHash('  user@example.COM ', '203.0.113.5'))->toBe($hash);
    expect(svc()->scopeHash('usér@example.com', '203.0.113.5'))->toBe(svc()->scopeHash('user@example.com', '203.0.113.5'));
    expect(svc()->scopeHash('user@example.com', '203.0.113.6'))->not->toBe($hash);
    expect(svc()->scopeHash('other@example.com', '203.0.113.5'))->not->toBe($hash);
    expect(svc()->scopeHash('user@example.com', null))->toMatch('/^[0-9a-f]{64}$/');
});

test('the stored row contains only the opaque scope, counters and instants', function () {
    try {
        svc()->run(SVC_EMAIL, SVC_IP, fn () => throw svcWrongPassword());
    } catch (ValidationException) {
    }

    $raw = json_encode(DB::table('login_lockouts')->first());

    expect($raw)->not->toContain('svc@example.com')->not->toContain(SVC_IP)->not->toContain('password');
    expect(array_keys((array) DB::table('login_lockouts')->first()))
        ->toBe(['id', 'scope_hash', 'failed_count', 'window_started_at', 'locked_until']);
});

// ---------------------------------------------------------------- cleanup

test('pruning removes expired locks and ended windows and never an active lock', function () {
    $now = now()->getTimestamp();
    $row = fn (string $name, int $windowStart, ?int $lockedUntil) => LoginLockout::create([
        'scope_hash' => hash('sha256', $name),
        'failed_count' => 3,
        'window_started_at' => $windowStart,
        'locked_until' => $lockedUntil,
    ]);

    $row('expired-lock', $now - 5000, $now - 1);         // lock ended: prune
    $row('lock-ends-now', $now - 5000, $now);            // locked_until <= now: prune
    $row('window-ended', $now - 900, null);              // window over, never locked: prune
    $row('active-lock-old-window', $now - 5000, $now + 60); // lock still running: KEEP
    $row('window-running', $now - 899, null);            // window still open: KEEP

    Artisan::call('model:prune', ['--model' => LoginLockout::class]);

    $kept = LoginLockout::query()->orderBy('id')->pluck('scope_hash')->all();
    expect($kept)->toBe([hash('sha256', 'active-lock-old-window'), hash('sha256', 'window-running')]);
});

test('an expired lock is deleted when next seen, even if never pruned', function () {
    config(['auth-security.login.max_failures' => 1]);
    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, fn () => throw svcWrongPassword()))->toThrow(LoginLockedException::class);

    $this->travel(1800)->seconds();

    expect(svc()->run(SVC_EMAIL, SVC_IP, fn () => 'open-again'))->toBe('open-again');
    expect(svcRow())->toBeNull();
});

// ------------------------------------------------- review follow-ups (Meriem)

test('spellings the database would match fold to one scope: accents, NBSP, soft hyphen, zero-width', function () {
    $base = svc()->scopeHash('user@example.com', SVC_IP);

    foreach ([
        "us\u{00E9}r@example.com",          // accent (utf8mb4_unicode_ci is accent-insensitive)
        "user@example.com\u{00A0}",         // trailing NBSP: trim() alone would keep it
        "us\u{00AD}er@example.com",         // soft hyphen inside
        "us\u{200B}er@example.com",         // zero-width space inside
        " \tUSER@Example.COM\n",            // case and whitespace
    ] as $variant) {
        expect(svc()->scopeHash($variant, SVC_IP))->toBe($base, 'variant '.json_encode($variant));
    }

    expect(svc()->scopeHash('other@example.com', SVC_IP))->not->toBe($base);
});

test('a slow password check that straddles the window end does not pile onto the old count', function () {
    LoginLockout::create([
        'scope_hash' => svc()->scopeHash(SVC_EMAIL, SVC_IP),
        'failed_count' => 2,
        'window_started_at' => now()->getTimestamp(),
    ]);

    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, function () {
        $this->travel(901)->seconds(); // the window (900 s) ends while the hash runs

        throw svcWrongPassword();
    }))->toThrow(ValidationException::class); // 1 in a NEW window, not the 3rd failure

    expect(svcRow()->failed_count)->toBe(1);
    expect(svcRow()->locked_until)->toBeNull();
    expect(svcRow()->window_started_at)->toBe(now()->getTimestamp());
});

test('a scope left at the threshold without a lock (crash mid-recording) is locked on next sight', function () {
    LoginLockout::create([
        'scope_hash' => svc()->scopeHash(SVC_EMAIL, SVC_IP),
        'failed_count' => 3,
        'window_started_at' => now()->getTimestamp(),
        'locked_until' => null,
    ]);
    $verified = false;
    $start = now()->getTimestamp();

    try {
        svc()->run(SVC_EMAIL, SVC_IP, function () use (&$verified) {
            $verified = true;
        });
        $this->fail('Expected a LoginLockedException.');
    } catch (LoginLockedException $e) {
        expect($e->lockedUntil)->toBe($start + 1800);
    }

    expect($verified)->toBeFalse(); // no free password check
    expect(svcRow()->locked_until)->toBe($start + 1800);
});

test('a storage error on the final read is a lost decision, a different error propagates, and the row is untouched', function () {
    LoginLockout::create([
        'scope_hash' => svc()->scopeHash(SVC_EMAIL, SVC_IP),
        'failed_count' => 1,
        'window_started_at' => now()->getTimestamp(),
    ]);
    $failing = null;

    DB::connection()->beforeExecuting(function (string $sql) use (&$failing) {
        if ($failing !== null && preg_match('/^select [`"]locked_until[`"] from [`"]login_lockouts[`"] where [`"]scope_hash[`"]/', $sql) === 1) {
            throw $failing;
        }
    });

    $failing = new QueryException('sqlite', 'select "locked_until" from "login_lockouts" where "scope_hash" = ?', [], new PDOException('injected'));
    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, fn () => 'verified'))->toThrow(LoginLeaseLostException::class);
    expect(svcRow()->failed_count)->toBe(1)->and(svcRow()->locked_until)->toBeNull();

    $failing = new LogicException('bug');
    expect(fn () => svc()->run(SVC_EMAIL, SVC_IP, fn () => 'verified'))->toThrow(LogicException::class);
    expect(svcRow()->failed_count)->toBe(1)->and(svcRow()->locked_until)->toBeNull();
});
