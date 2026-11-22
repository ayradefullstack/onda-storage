<?php

declare(strict_types=1);

use App\Exceptions\Auth\LoginLeaseLostException;
use App\Http\Middleware\EnforceLoginLockout;
use App\Models\Auth\LoginLockout;
use App\Models\User;
use App\Services\Auth\LoginLockoutService;
use App\Services\Auth\LoginNotificationService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The FINAL lockout read (after the password was verified) failing, and the
 * rollback of the provisional login it forces.
 *
 * - a storage error there is a lost decision: generic busy 429, login undone;
 * - any other error there is a bug: login undone, the error propagates (500);
 * - the rollback never writes users.remember_token and never fires Logout.
 *
 * Failure injection is test-only: a beforeExecuting hook on the connection
 * that throws for exactly the final read (`select "locked_until" from
 * "login_lockouts" where "scope_hash" ...`). Armed right before the POST under
 * test; `hits` proves it really fired.
 *
 * Regression coverage of the ordinary paths lives elsewhere: plain login and
 * clear in LoginLockoutCloseoutTest, confirmed-2FA challenge and completion
 * with a recovery code in LoginLockoutTest ('failures at the second-factor
 * challenge never enter the password counter') and TwoFactorChallengeTest.
 */
const FC_IP = '198.51.100.91';

function fcSvc(): LoginLockoutService
{
    return app(LoginLockoutService::class);
}

function fcRow(string $email, string $ip = FC_IP): ?LoginLockout
{
    return LoginLockout::query()->where('scope_hash', fcSvc()->scopeHash($email, $ip))->first();
}

function fcSeed(string $email, int $failures = 1, string $ip = FC_IP): LoginLockout
{
    return LoginLockout::create([
        'scope_hash' => fcSvc()->scopeHash($email, $ip),
        'failed_count' => $failures,
        'window_started_at' => now()->getTimestamp(),
        'locked_until' => null,
    ]);
}

function fcClearThrottles(string $email, string $ip = FC_IP): void
{
    RateLimiter::clear(md5('login'.Str::transliterate(Str::lower(trim($email)).'|'.$ip)));
    RateLimiter::clear(md5('login'.'ip:'.$ip));
}

function fcLogin(mixed $test, string $email, string $password, bool $remember = false, string $ip = FC_IP): TestResponse
{
    fcClearThrottles($email, $ip);
    $data = ['email' => $email, 'password' => $password];

    if ($remember) {
        $data['remember'] = 'on';
    }

    return $test->withServerVariables(['REMOTE_ADDR' => $ip])->post(route('login.store'), $data);
}

/** Make the final lockout read throw $e. Returns a counter of how often it fired. */
function fcFailFinalRead(Throwable $e): stdClass
{
    $state = new stdClass;
    $state->hits = 0;

    DB::connection()->beforeExecuting(function (string $sql) use ($e, $state): void {
        if (preg_match('/^select [`"]locked_until[`"] from [`"]login_lockouts[`"] where [`"]scope_hash[`"]/', $sql) === 1) {
            $state->hits++;

            throw $e;
        }
    });

    return $state;
}

function fcStorageError(): QueryException
{
    return new QueryException('sqlite', 'select "locked_until" from "login_lockouts" where "scope_hash" = ?', [], new PDOException('injected'));
}

function fcLogoutCounter(): stdClass
{
    $counter = new stdClass;
    $counter->count = 0;
    Event::listen(Logout::class, function () use ($counter): void {
        $counter->count++;
    });

    return $counter;
}

function fcHasRecallerCookie(TestResponse $response): bool
{
    $recaller = Auth::guard('web')->getRecallerName();

    return collect($response->headers->getCookies())
        ->contains(fn ($c) => $c->getName() === $recaller && $c->getValue() !== '');
}

beforeEach(function () {
    $this->freezeTime();
    config(['auth-security.login.ip_max_requests' => 1000]);
});

// ===================================== storage error on the final read -> 429

test('a storage error on the final read refuses a remember-me login without side effects', function () {
    $user = User::factory()->create();
    $tokenBefore = $user->remember_token;
    expect($tokenBefore)->not->toBeEmpty();
    fcSeed($user->email);

    $this->withSession(['fc' => 1]);
    $sessionIdBefore = session()->getId();
    $csrfBefore = session()->token();
    $logouts = fcLogoutCounter();

    $failure = fcFailFinalRead(fcStorageError());
    $response = fcLogin($this, $user->email, 'password', remember: true);

    expect($failure->hits)->toBeGreaterThan(0);
    $response->assertStatus(429);
    expect($response->headers->get('Retry-After'))->toBe('2');
    expect($response->headers->has('X-Locked-Until'))->toBeFalse();
    expect(fcHasRecallerCookie($response))->toBeFalse();
    expect($user->fresh()->remember_token)->toBe($tokenBefore);
    expect($logouts->count)->toBe(0);
    expect(session()->getId())->not->toBe($sessionIdBefore);
    expect(session()->token())->not->toBe($csrfBefore);

    $this->assertGuest();
    $this->get(route('author.dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();

    expect(LoginLockout::count())->toBe(1);
    expect(fcRow($user->email)->failed_count)->toBe(1);
});

test('a storage error on the final read with no stored remember token still leaves no cookie', function () {
    $user = User::factory()->create(['remember_token' => null]);

    $failure = fcFailFinalRead(fcStorageError());
    $response = fcLogin($this, $user->email, 'password', remember: true);

    expect($failure->hits)->toBeGreaterThan(0);
    $response->assertStatus(429);
    expect(fcHasRecallerCookie($response))->toBeFalse();
    $this->assertGuest();
    // The token itself is deliberately not asserted: an empty token may be
    // newly written by the attempt and is intentionally not reverted.
});

test('a storage error on the final read for a confirmed-2FA user leaves no challenge', function () {
    $user = User::factory()->withTwoFactor()->create();

    $failure = fcFailFinalRead(fcStorageError());
    $response = fcLogin($this, $user->email, 'password');

    expect($failure->hits)->toBeGreaterThan(0);
    $response->assertStatus(429);
    expect(session()->has('login.id'))->toBeFalse();
    $this->assertGuest();

    $this->get(route('two-factor.login'))->assertRedirect(route('login'));

    $this->withServerVariables(['REMOTE_ADDR' => FC_IP])
        ->post(route('two-factor.login'), ['recovery_code' => 'recovery-code-1']);
    $this->assertGuest();
});

test('a storage error on the final read keeps an earlier valid challenge of another user', function () {
    $a = User::factory()->withTwoFactor()->create();
    $b = User::factory()->withTwoFactor()->create();

    fcLogin($this, $a->email, 'password')->assertRedirect(route('two-factor.login'));
    expect(session('login.id'))->toBe($a->id);

    $failure = fcFailFinalRead(fcStorageError());
    fcLogin($this, $b->email, 'password')->assertStatus(429);

    expect($failure->hits)->toBeGreaterThan(0);
    expect(session('login.id'))->toBe($a->id);
    $this->assertGuest();

    $this->withServerVariables(['REMOTE_ADDR' => FC_IP])
        ->post(route('two-factor.login'), ['recovery_code' => 'recovery-code-1']);
    $this->assertAuthenticatedAs($a);
});

// ==================================== unexpected error on the final read -> 500

test('an unexpected error on the final read is a 500, not a busy 429, and the login is undone', function () {
    $user = User::factory()->create();
    $tokenBefore = $user->remember_token;
    $logouts = fcLogoutCounter();

    $failure = fcFailFinalRead(new LogicException('bug'));
    $response = fcLogin($this, $user->email, 'password', remember: true);

    expect($failure->hits)->toBeGreaterThan(0);
    $response->assertStatus(500);
    expect(fcHasRecallerCookie($response))->toBeFalse();
    expect($user->fresh()->remember_token)->toBe($tokenBefore);
    expect($logouts->count)->toBe(0);
    $this->assertGuest();
    $this->get(route('author.dashboard'))->assertRedirect(route('login'));
});

test('an unexpected error on the final read for a confirmed-2FA user leaves no challenge', function () {
    $user = User::factory()->withTwoFactor()->create();

    $failure = fcFailFinalRead(new LogicException('bug'));
    $response = fcLogin($this, $user->email, 'password');

    expect($failure->hits)->toBeGreaterThan(0);
    $response->assertStatus(500);
    expect(session()->has('login.id'))->toBeFalse();
    $this->assertGuest();
});

// ============================================================ stage level

test('a login created by an earlier stage and followed by a non-response is taken back, cookie included', function () {
    $user = User::factory()->create();
    $recaller = Auth::guard('web')->getRecallerName();
    $guardKey = Auth::guard('web')->getName();
    $request = Request::create('/login', 'POST', ['email' => 'stage@example.com', 'password' => 'x'], server: ['REMOTE_ADDR' => FC_IP]);
    $request->setLaravelSession(app('session.store'));
    $queuedInside = false;

    $stage = function () use ($request, $user, $recaller, &$queuedInside) {
        return app(EnforceLoginLockout::class)->handle($request, function () use ($user, $recaller, &$queuedInside) {
            Auth::login($user, true);
            $queuedInside = Auth::guard('web')->getCookieJar()->hasQueued($recaller);

            return 'not-a-response';
        });
    };

    expect($stage)->toThrow(RuntimeException::class);

    expect($queuedInside)->toBeTrue();
    expect(Auth::guard('web')->getCookieJar()->hasQueued($recaller))->toBeFalse();
    expect($request->session()->has($guardKey))->toBeFalse();
    $this->assertGuest();
});

// ============================================================ regression

test('an ordinary remember-me login still authenticates and issues the remember cookie', function () {
    $user = User::factory()->create();

    $response = fcLogin($this, $user->email, 'password', remember: true);

    $response->assertRedirect();
    $this->assertAuthenticatedAs($user);
    expect(fcHasRecallerCookie($response))->toBeTrue();
});

// ================================= failure at the FIRST gate read (stage entry)

/**
 * The lockout gate (first read of the scope row, before $verify may run) failing
 * for a request that carries an earlier, valid 2FA challenge. The pipeline never
 * ran, so this request created no authentication: the earlier challenge, the
 * session id and the CSRF token must all survive untouched.
 *
 * ONE-SHOT: recordFailure() re-reads with the identical SQL, and the later
 * two-factor POST must run clean, so the hook disarms itself after one throw.
 * $pattern defaults to the gate read (`select * from login_lockouts where
 * scope_hash = ? limit 1`, what Eloquent first() emits on sqlite/MySQL).
 */
const FC_GATE_READ = '/^select \* from [`"]login_lockouts[`"] where [`"]scope_hash[`"] = \? limit 1$/';
const FC_GATE_DELETE = '/^delete from [`"]login_lockouts[`"] where [`"]login_lockouts[`"]\.[`"]id[`"] = \?$/';
const FC_GATE_LOCK_UPDATE = '/^update [`"]login_lockouts[`"] set [`"]locked_until[`"] = \?/';

function fcFailGateReadOnce(Throwable $e, string $pattern = FC_GATE_READ): stdClass
{
    $state = new stdClass;
    $state->hits = 0;
    $state->armed = true;

    DB::connection()->beforeExecuting(function (string $sql) use ($e, $state, $pattern): void {
        if ($state->armed && preg_match($pattern, $sql) === 1) {
            $state->armed = false;
            $state->hits++;

            throw $e;
        }
    });

    return $state;
}

function fcGateError(string $kind): Throwable
{
    return $kind === 'storage' ? fcStorageError() : new LogicException('bug at the gate');
}

/**
 * Whole scenario: challenge for A, arm, second real POST, continuity checks,
 * then complete the 2FA with a recovery code.
 *
 * @param  'same'|'other'|'wrong'  $second
 */
function fcGateFailureKeepsChallenge(mixed $test, string $second, string $errorKind, string $pattern = FC_GATE_READ, ?Closure $seed = null): void
{
    $a = User::factory()->withTwoFactor()->create();
    $b = User::factory()->withTwoFactor()->create();

    fcLogin($test, $a->email, 'password')->assertRedirect(route('two-factor.login'));
    expect(session('login.id'))->toBe($a->id);

    $sessionId = session()->getId();
    $csrf = session()->token();

    if ($seed !== null) {
        $seed($a, $b);
    }

    $failure = fcFailGateReadOnce(fcGateError($errorKind), $pattern);

    // The test client does not replay the session cookie by itself, so every
    // request would get a fresh session id whatever the code does. Send the real
    // cookie, so that an unchanged id proves the failed request left it alone.
    $cookieName = (string) config('session.cookie');
    $encrypter = app('encrypter');
    $test->withUnencryptedCookie($cookieName, $encrypter->encrypt(
        CookieValuePrefix::create($cookieName, $encrypter->getKey()).$sessionId,
        EncryptCookies::serialized($cookieName),
    ));

    $response = match ($second) {
        'same', 'same user, correct password' => fcLogin($test, $a->email, 'password'),
        'other', 'another 2FA user' => fcLogin($test, $b->email, 'password'),
        'wrong', 'wrong password' => fcLogin($test, $a->email, 'wrong-password'),
    };

    expect($failure->hits)->toBe(1);
    $response->assertStatus(500);

    expect(session('login.id'))->toBe($a->id);
    expect(session()->getId())->toBe($sessionId);
    expect(session()->token())->toBe($csrf);
    $test->assertGuest();

    $test->withServerVariables(['REMOTE_ADDR' => FC_IP])
        ->post(route('two-factor.login'), ['recovery_code' => 'recovery-code-1']);
    $test->assertAuthenticatedAs($a);
}

test('a failure at the first gate read leaves the earlier challenge, session id and CSRF token alone', function (string $second, string $errorKind) {
    fcGateFailureKeepsChallenge($this, $second, $errorKind);

    expect(LoginLockout::count())->toBe(0);
})->with(['same user, correct password', 'another 2FA user', 'wrong password'])
    ->with(['logic', 'storage']);

test('a failure while deleting an expired row at the gate keeps the earlier challenge', function () {
    fcGateFailureKeepsChallenge($this, 'same', 'storage', FC_GATE_DELETE, function (User $a): void {
        LoginLockout::create([
            'scope_hash' => fcSvc()->scopeHash($a->email, FC_IP),
            'failed_count' => 1,
            'window_started_at' => now()->getTimestamp() - 100000,
            'locked_until' => null,
        ]);
    });
});

test('a failure while self-healing a row at the threshold keeps the earlier challenge', function () {
    fcGateFailureKeepsChallenge($this, 'same', 'logic', FC_GATE_LOCK_UPDATE, function (User $a): void {
        fcSeed($a->email, 3);
    });
});

// ------------------------------------------------ stage level, service replaced

function fcStageRequest(): Request
{
    $request = Request::create('/login', 'POST', ['email' => 'stage-gate@example.com', 'password' => 'x'], server: ['REMOTE_ADDR' => FC_IP]);
    $session = app('session.store');
    $session->put('_token', Str::random(40));
    $request->setLaravelSession($session);

    return $request;
}

/** A service whose run() fails before $verify can ever be called. */
function fcServiceThrowing(Throwable $e): LoginLockoutService
{
    return new class($e) extends LoginLockoutService
    {
        public function __construct(private readonly Throwable $e) {}

        public function run(string $email, ?string $ip, Closure $verify): mixed
        {
            throw $this->e;
        }
    };
}

test('a stage whose service fails before verify keeps the challenge, session id and token', function () {
    $request = fcStageRequest();
    $session = $request->session();
    $session->put('login.id', 7);
    $sessionId = $session->getId();
    $csrf = $session->token();
    $nextCalled = false;

    $stage = function () use ($request, &$nextCalled) {
        return (new EnforceLoginLockout(fcServiceThrowing(new LogicException('gate')), app(LoginNotificationService::class)))
            ->handle($request, function () use (&$nextCalled) {
                $nextCalled = true;

                return response('x');
            });
    };

    expect($stage)->toThrow(LogicException::class);

    expect($nextCalled)->toBeFalse();
    expect($session->get('login.id'))->toBe(7);
    expect($session->getId())->toBe($sessionId);
    expect($session->token())->toBe($csrf);
});

test('a lost lease before the pipeline started answers busy and leaves the session untouched', function () {
    $request = fcStageRequest();
    $session = $request->session();
    $session->put('login.id', 7);
    $sessionId = $session->getId();
    $csrf = $session->token();

    $response = (new EnforceLoginLockout(fcServiceThrowing(new LoginLeaseLostException), app(LoginNotificationService::class)))
        ->handle($request, fn () => response('x'));

    expect($response->getStatusCode())->toBe(429);
    expect($response->headers->get('Retry-After'))->toBe('2');
    expect($response->headers->get('X-Login-Reason'))->toBe('busy');
    expect($session->get('login.id'))->toBe(7);
    expect($session->getId())->toBe($sessionId);
    expect($session->token())->toBe($csrf);
});

test('once the pipeline started, an error still restores the old challenge, drops the new one and rotates the session', function () {
    $request = fcStageRequest();
    $session = $request->session();
    $session->put('login.id', 7);
    $sessionId = $session->getId();

    $stage = function () use ($request) {
        return app(EnforceLoginLockout::class)->handle($request, function () use ($request) {
            // A fresh challenge for another user, written by this request.
            $request->session()->put('login.id', 8);

            throw new LogicException('after the password check');
        });
    };

    expect($stage)->toThrow(LogicException::class);

    expect($session->get('login.id'))->toBe(7);
    expect($session->getId())->not->toBe($sessionId);
});
