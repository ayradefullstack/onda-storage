<?php

/*
|--------------------------------------------------------------------------
| Authentication security settings
|--------------------------------------------------------------------------
|
| Every threshold and duration of the password-login protections lives here,
| fed by .env (documented in .env.example). `env()` is read ONLY in this file;
| application code reads `config('auth-security.…')`.
|
| Invalid values never disable a protection. A value that is not a whole
| number inside its documented range falls back to the default below and its
| key is listed in `auth-security.invalid`; the login limiter logs that list
| (key names only, never values) on every login attempt until it is fixed.
| A bad .env line therefore degrades to the safe default instead of taking
| the site down (a config error here would also break `artisan config:clear`).
|
*/

/**
 * @param  list<string>  $invalid
 */
$positiveInt = static function (string $name, mixed $raw, int $default, int $max, array &$invalid): int {
    if ($raw === null) {
        return $default;
    }

    $value = filter_var(is_scalar($raw) ? (string) $raw : '', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => $max],
    ]);

    if ($value === false) {
        $invalid[] = $name;

        return $default;
    }

    return $value;
};

$invalid = [];

return [

    'login' => [

        /*
         * Reserved for the per-account lockout (a later phase; nothing reads
         * these yet): this many password failures within the window lock the
         * normalized email + IP pair for the lockout duration.
         * Allowed: failures 1–100, seconds 1–86400.
         */
        'max_failures' => $positiveInt('AUTH_LOGIN_MAX_FAILURES', env('AUTH_LOGIN_MAX_FAILURES'), 3, 100, $invalid),
        'failure_window_seconds' => $positiveInt('AUTH_LOGIN_FAILURE_WINDOW_SECONDS', env('AUTH_LOGIN_FAILURE_WINDOW_SECONDS'), 900, 86400, $invalid),
        'lockout_seconds' => $positiveInt('AUTH_LOGIN_LOCKOUT_SECONDS', env('AUTH_LOGIN_LOCKOUT_SECONDS'), 1800, 86400, $invalid),

        /*
         * Independent per-IP budget for POST /login: every submission counts,
         * whatever the email and whether or not the password was right. The
         * bucket is shared across all emails sent from one IP.
         * Allowed: requests 1–10000, seconds 1–86400.
         * Initial values, to be reviewed against real traffic.
         */
        'ip_max_requests' => $positiveInt('AUTH_LOGIN_IP_MAX_REQUESTS', env('AUTH_LOGIN_IP_MAX_REQUESTS'), 20, 10000, $invalid),
        'ip_window_seconds' => $positiveInt('AUTH_LOGIN_IP_WINDOW_SECONDS', env('AUTH_LOGIN_IP_WINDOW_SECONDS'), 60, 86400, $invalid),

    ],

    /*
     * Mechanics of the per-scope mutex around one password verification
     * (see App\Services\Auth\LoginLockoutService). Plain values, no .env:
     * they are not policy. The service clamps them (wait >= 0, ttl >= 5).
     *  - lock_store: a cache store with lock support that is SHARED between
     *    PHP workers. `database` (the cache_locks table) needs no Redis.
     *  - lock_wait_seconds: how long a request waits for the mutex before
     *    failing closed with a 429. 0 = do not wait.
     *  - lock_ttl_seconds: the mutex expires by itself after this. Time spent
     *    WAITING for it does not count: the clock starts once the mutex is
     *    acquired, and a verified password is admitted only if the attempt
     *    finished within (ttl - 5) seconds of acquiring it (the 5 s is a
     *    margin for clock drift between web hosts; minimum 1 s). That window
     *    covers two password hashes, the session work and the lockout
     *    queries; a slower attempt is refused as busy (429) even with the
     *    right password.
     */
    'lockout' => [
        'lock_store' => 'database',
        'lock_wait_seconds' => 3,
        'lock_ttl_seconds' => 30,
    ],

    /** Env keys whose value was rejected and replaced by the default. */
    'invalid' => $invalid,

];
