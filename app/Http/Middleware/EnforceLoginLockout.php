<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Auth\LoginAttemptInconclusiveException;
use App\Exceptions\Auth\LoginLeaseLostException;
use App\Exceptions\Auth\LoginLockedException;
use App\Exceptions\Auth\LoginLockoutBusyException;
use App\Models\User;
use App\Services\Auth\LoginAttemptState;
use App\Services\Auth\LoginLockoutService;
use App\Services\Auth\LoginMethod;
use App\Services\Auth\LoginNotificationService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A stage of Fortify's LOGIN PIPELINE (not a route middleware: see
 * FortifyServiceProvider::configureLoginPipeline), placed right after
 * CanonicalizeUsername and before both password-verification stages
 * (RedirectIfTwoFactorAuthenticatable and AttemptToAuthenticate). Wrapping
 * both is what makes one failed request count exactly once.
 *
 * It only translates: the rules live in LoginLockoutService.
 *
 * A login 429 has three causes, told apart by the X-Login-Reason header:
 *   busy      X-Login-Reason: busy    lockout state momentarily unavailable
 *   locked    X-Login-Reason: locked  scope locked (or just reached the threshold)
 *   throttle  no X-Login-Reason       Laravel's request throttle, not this stage
 * The header VALUES are a machine contract; the message wording is not. Clients
 * must never infer the cause from Retry-After or from the message text.
 *
 * Both responses carry a generic localized message (nothing about whether the
 * account exists) and Retry-After (seconds, >= 1). A locked response adds
 *   X-Locked-Until  absolute UTC instant, ISO-8601 (…Z)
 *   X-Server-Time   server clock at the decision, ISO-8601 UTC
 * JSON clients also get these in the body, plus "reason". The Inertia login
 * form sends a normal browser Accept header, so it receives the plain-text
 * variant (headers only, no "reason" body field).
 *
 * Rollback (undoAuthentication) cannot reverse the irreversible side effects
 * of a login that was created before it was admitted: the Login /
 * TwoFactorAuthenticationChallenged events already fired, Fortify's
 * LoginRateLimiter::clear already ran, a password rehash may have been
 * written, and an empty remember_token may have been newly created.
 */
class EnforceLoginLockout
{
    public const REASON_HEADER = 'X-Login-Reason';

    public const REASON_BUSY = 'busy';

    public const REASON_LOCKED = 'locked';

    /** Seconds a client is told to wait when the lockout state is busy. */
    private const BUSY_RETRY_AFTER = 2;

    public function __construct(
        private readonly LoginLockoutService $lockouts,
        private readonly LoginNotificationService $notifier,
    ) {}

    private const CHALLENGE_KEYS = ['login.id', 'login.remember'];

    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->input(Fortify::username());

        if (! is_string($email)) {
            // Fortify's LoginRequest validation (`required|string`) answers 422
            // before the pipeline, so this is unreachable today. If the order
            // ever changes, fail closed rather than skip the lockout.
            throw new RuntimeException('The login username must be a string.');
        }

        $attempt = new LoginAttemptState;

        try {
            $response = $this->lockouts->run(
                $email,
                $request->ip(),
                function () use ($request, $next, $attempt): mixed {
                    // Success evidence must belong to THIS request. A pending 2FA
                    // challenge from an earlier request leaves `login.id` in the
                    // session; set it aside so that only a fresh one counts, and
                    // put it back unless this attempt really verified a password.
                    $this->setAsidePreviousChallenge($request, $attempt);

                    // Undo rule: an undo reachable only from inside this closure
                    // runs after started = true and needs no guard; one reachable
                    // from the outer catches does (a gate failure never gets here).
                    try {
                        $response = $next($request);

                        if (! $response instanceof Response) {
                            // Not a response: nothing here can be called a verdict.
                            // Fail closed (a 500), never "verified".
                            throw new RuntimeException('Unexpected result from the login pipeline.');
                        }

                        // Fortify runs its stages through Illuminate\Routing\Pipeline,
                        // which does NOT let a stage's exception travel up: it renders
                        // it and returns the response, with the original exception
                        // attached (Routing\Pipeline::handleException ->
                        // withException). So a rejected password reaches this stage as
                        // a normal return. Recognise it from the attached exception
                        // and turn it back into the one thing the service counts.
                        $exception = $this->attachedException($response);

                        if ($exception === null) {
                            // "No exception" is not proof of a verified password (a
                            // stage may return some other response). Require the
                            // positive evidence Fortify leaves, produced by this
                            // request: the user is logged in, or the 2FA challenge
                            // was issued for them.
                            if ($this->passwordVerified($request, $attempt)) {
                                $attempt->verified = true;

                                return $response;
                            }

                            throw new LoginAttemptInconclusiveException($response);
                        }

                        if ($exception instanceof ValidationException && array_key_exists(Fortify::username(), $exception->errors())) {
                            // A login was already created for this request: the
                            // password is not wrong, so this is no failure to
                            // count. Undo it and report no verdict.
                            if ($this->passwordVerified($request, $attempt)) {
                                $this->undoAuthentication($request, $attempt);

                                throw new LoginAttemptInconclusiveException($response);
                            }

                            $attempt->response = $response;

                            throw $exception;
                        }

                        // Some other error: no verdict on the password either way.
                        // But if a login was already created by an earlier stage,
                        // it was never checked against the lease or the lock.
                        if ($this->passwordVerified($request, $attempt)) {
                            $this->undoAuthentication($request, $attempt);
                        }

                        throw new LoginAttemptInconclusiveException($response);
                    } finally {
                        if (! $attempt->verified) {
                            $this->restorePreviousChallenge($request, $attempt);
                        }
                    }
                },
            );
        } catch (LoginLockedException $e) {
            if ($attempt->response !== null && $request->hasSession()) {
                // This request WAS the failure that reached the threshold: the
                // pipeline already flashed "invalid credentials" + old input
                // for a redirect that is now replaced by the 429. Drop them so
                // they do not resurface on the next page.
                $request->session()->forget(['errors', '_old_input']);
            }

            return $this->locked($request, $e);
        } catch (LoginLeaseLostException) {
            // The password was right but the mutex lease ran out meanwhile, so
            // this request may not admit the login: undo what it created.
            if ($attempt->started) {
                $this->undoAuthentication($request, $attempt);
            }

            return $this->busy($request);
        } catch (LoginLockoutBusyException) {
            return $this->busy($request);
        } catch (LoginAttemptInconclusiveException $e) {
            return $e->response;
        } catch (ValidationException $e) {
            // A rejected password that did not lock the scope. Give back the
            // response the pipeline already rendered for it; if the exception
            // came straight from $next (a plain, non-routing pipeline), let it
            // propagate so the outer stage renders it as usual.
            return $attempt->response ?? throw $e;
        } catch (Throwable $e) {
            // Anything unexpected (e.g. a storage error while admitting the
            // login): a login this attempt created must not outlive the error.
            // Guarded by started: a failure at the first gate read happens
            // before this request touched the session, so a pending challenge
            // from an earlier request must not be mistaken for its own login.
            if ($attempt->started && ($attempt->verified || $this->passwordVerified($request, $attempt))) {
                $this->undoAuthentication($request, $attempt);
            }

            throw $e;
        }

        // Only the success path gets here: every catch above returns or
        // throws. Outside the try on purpose, so that nothing the alert does
        // can be mistaken for a failed login and undo an admitted one. A
        // confirmed-2FA user ends with `verified` but a fresh `login.id`
        // (challenge pending, not yet signed in): no alert until the code is
        // accepted (TwoFactorLoginResponse).
        if (
            $attempt->verified
            && $request->hasSession()
            && ! $request->session()->has('login.id')
            && $this->guardInSession($request)
        ) {
            $user = $this->guard()->user();

            if ($user instanceof User) {
                $this->notifier->accepted($user, LoginMethod::Password);
            }
        }

        return $response;
    }

    private function setAsidePreviousChallenge(Request $request, LoginAttemptState $attempt): void
    {
        $attempt->started = true;
        $attempt->wasAuthenticated = $this->guardInSession($request);

        if (! $request->hasSession()) {
            return;
        }

        foreach (self::CHALLENGE_KEYS as $key) {
            if ($request->session()->has($key)) {
                $attempt->previous[$key] = $request->session()->pull($key);
            }
        }
    }

    private function restorePreviousChallenge(Request $request, LoginAttemptState $attempt): void
    {
        if (! $request->hasSession()) {
            return;
        }

        foreach (self::CHALLENGE_KEYS as $key) {
            $request->session()->forget($key);
        }

        foreach ($attempt->previous as $key => $value) {
            $request->session()->put($key, $value);
        }
    }

    /**
     * Take back an authentication this request created but may not keep.
     *
     * Takes back only what THIS attempt created: the guard's session key, the
     * queued remember-me cookie and the in-memory user. It deliberately does
     * not call logout(): that rotates the persisted remember_token (signing
     * the user out of their other devices) and dispatches Logout for a login
     * that was never admitted. It never touches the lockout row. A
     * remember_token newly created by this attempt (ensureRememberTokenIsSet
     * on an empty value) is intentionally not reverted: it was never
     * delivered to any client.
     */
    private function undoAuthentication(Request $request, LoginAttemptState $attempt): void
    {
        if (! $attempt->wasAuthenticated && $this->guardInSession($request)) {
            $guard = $this->guard();

            if ($guard instanceof SessionGuard) {
                $guard->getSession()->remove($guard->getName());
                $guard->getCookieJar()->unqueue($guard->getRecallerName());
                $guard->forgetUser();
            } else {
                $guard->logout();
            }
        }

        $this->restorePreviousChallenge($request, $attempt);

        if ($request->hasSession()) {
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();
        }
    }

    private function guard(): StatefulGuard
    {
        return Auth::guard((string) config('fortify.guard'));
    }

    /**
     * Fortify's own outcomes for a correct password: ordinary login leaves the
     * guard authenticated (AttemptToAuthenticate); a confirmed-2FA user gets a
     * fresh `login.id` in the session and the challenge redirect
     * (RedirectIfTwoFactorAuthenticatable). The earlier challenge was set aside
     * before the pipeline ran, so a key present now was written by this request.
     */
    private function passwordVerified(Request $request, LoginAttemptState $attempt): bool
    {
        if ($request->hasSession() && $request->session()->has('login.id')) {
            return true;
        }

        return ! $attempt->wasAuthenticated && $this->guardInSession($request);
    }

    /**
     * Is a user stored in the session for the guard? Not guard->check(): that
     * can also authenticate from a remember-me cookie, which is not something
     * this request's password check produced.
     */
    private function guardInSession(Request $request): bool
    {
        $guard = $this->guard();

        if (! $guard instanceof SessionGuard) {
            return $guard->check();
        }

        return $request->hasSession() && $request->session()->has($guard->getName());
    }

    /**
     * The exception the routing pipeline attached to a rendered response.
     */
    private function attachedException(mixed $response): ?Throwable
    {
        if (! is_object($response) || ! property_exists($response, 'exception')) {
            return null;
        }

        return $response->exception instanceof Throwable ? $response->exception : null;
    }

    private function locked(Request $request, LoginLockedException $e): Response
    {
        $retryAfter = max(1, $e->lockedUntil - $e->serverTime);
        $lockedUntil = CarbonImmutable::createFromTimestampUTC($e->lockedUntil)->toIso8601ZuluString();
        $serverTime = CarbonImmutable::createFromTimestampUTC($e->serverTime)->toIso8601ZuluString();
        $message = __('lockout.locked');

        $headers = [
            'Retry-After' => (string) $retryAfter,
            'X-Locked-Until' => $lockedUntil,
            'X-Server-Time' => $serverTime,
            self::REASON_HEADER => self::REASON_LOCKED,
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'reason' => self::REASON_LOCKED,
                'message' => $message,
                'errors' => [Fortify::username() => [$message]],
                'locked_until' => $lockedUntil,
                'server_time' => $serverTime,
                'retry_after' => $retryAfter,
            ], 429, $headers);
        }

        return response($message, 429, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function busy(Request $request): Response
    {
        $message = __('lockout.busy');
        $headers = [
            'Retry-After' => (string) self::BUSY_RETRY_AFTER,
            self::REASON_HEADER => self::REASON_BUSY,
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'reason' => self::REASON_BUSY,
                'message' => $message,
                'errors' => [Fortify::username() => [$message]],
                'retry_after' => self::BUSY_RETRY_AFTER,
            ], 429, $headers);
        }

        return response($message, 429, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
