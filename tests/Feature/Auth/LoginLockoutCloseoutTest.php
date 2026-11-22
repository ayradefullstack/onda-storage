<?php

declare(strict_types=1);

use App\Exceptions\Auth\LoginLeaseLostException;
use App\Exceptions\Auth\LoginLockedException;
use App\Http\Middleware\EnforceLoginLockout;
use App\Models\Auth\LoginLockout;
use App\Models\User;
use App\Services\Auth\LoginLockoutService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/**
 * Phase 3 close-out: lease ownership across the whole attempt, success
 * evidence that belongs to THIS request, malformed input at the throttle
 * boundary, and prune-versus-renew.
 *
 * SQLite :memory:, one process, frozen/travelled Carbon time. Real parallel
 * behavior on MySQL is LoginLockoutMysqlTest (opt-in, see its header).
 */
const CO_EMAIL = 'closeout@example.com';
const CO_IP = '198.51.100.55';

function coSvc(): LoginLockoutService
{
    return app(LoginLockoutService::class);
}

function coRow(string $email = CO_EMAIL, string $ip = CO_IP): ?LoginLockout
{
    return LoginLockout::query()->where('scope_hash', coSvc()->scopeHash($email, $ip))->first();
}

function coSeed(int $failures, ?int $lockedUntil = null, string $email = CO_EMAIL, string $ip = CO_IP): LoginLockout
{
    return LoginLockout::create([
        'scope_hash' => coSvc()->scopeHash($email, $ip),
        'failed_count' => $failures,
        'window_started_at' => now()->getTimestamp(),
        'locked_until' => $lockedUntil,
    ]);
}

/** Another request takes over the scope's mutex (as if our lease had expired). */
function coStealLease(string $email = CO_EMAIL, string $ip = CO_IP): void
{
    $lock = Cache::store('database')->getStore()->lock('login-lockout:'.coSvc()->scopeHash($email, $ip), 30);
    $lock->forceRelease();
    expect($lock->get())->toBeTrue();
}

function coWrong(): ValidationException
{
    return ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
}

function coClearThrottles(string $email, string $ip): void
{
    RateLimiter::clear(md5('login'.Str::transliterate(Str::lower(trim($email)).'|'.$ip)));
    RateLimiter::clear(md5('login'.'ip:'.$ip));
}

function coLogin(mixed $test, string $email, string $password, string $ip = CO_IP): TestResponse
{
    coClearThrottles($email, $ip);

    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['email' => $email, 'password' => $password]);
}

beforeEach(function () {
    $this->freezeTime();
    config(['auth-security.login.ip_max_requests' => 1000]);
});

// =========================================== 1. lease ownership (service)

test('a verified password whose lease was taken over is NOT admitted and erases nothing', function () {
    expect(fn () => coSvc()->run(CO_EMAIL, CO_IP, function () {
        // The lease expired mid-attempt and request B took the scope and wrote newer state.
        coStealLease();
        coSeed(2);

        return 'verified';
    }))->toThrow(LoginLeaseLostException::class);

    expect(coRow()->failed_count)->toBe(2);
});

test('a verified password whose lease simply ran out is not admitted either, and its state stays', function () {
    coSeed(1);

    expect(fn () => coSvc()->run(CO_EMAIL, CO_IP, function () {
        $this->travel(31)->seconds(); // past the 30 s lease, nobody else took it

        return 'verified';
    }))->toThrow(LoginLeaseLostException::class);

    expect(coRow()->failed_count)->toBe(1);
});

test('a failed password after the lease was taken over is still counted exactly once', function () {
    expect(fn () => coSvc()->run(CO_EMAIL, CO_IP, function () {
        coStealLease();

        throw coWrong();
    }))->toThrow(ValidationException::class);

    expect(coRow()->failed_count)->toBe(1);
});

test('the threshold failure of a lease-lost attempt sets locked_until once, atomically', function () {
    coSeed(2);

    expect(fn () => coSvc()->run(CO_EMAIL, CO_IP, function () {
        coStealLease();

        throw coWrong();
    }))->toThrow(LoginLockedException::class);

    expect(coRow()->failed_count)->toBe(3)
        ->and(coRow()->locked_until)->toBe(now()->getTimestamp() + 1800);
});

test('the lease is renewed for the clear and released afterwards', function () {
    coSeed(1);
    coSvc()->run(CO_EMAIL, CO_IP, fn () => 'verified');

    expect(coRow())->toBeNull();
    // released: an immediate second run is not busy
    expect(coSvc()->run(CO_EMAIL, CO_IP, fn () => 'again'))->toBe('again');
});

// ========================================= 1b. lease ownership (over HTTP)

test('over HTTP: a correct password whose lease expires inside the attempt is refused, logged out, and state is kept', function () {
    $user = User::factory()->create();
    coLogin($this, $user->email, 'wrong-password');
    coLogin($this, $user->email, 'wrong-password');
    expect(coRow($user->email)->failed_count)->toBe(2);

    // The Login event fires inside AttemptToAuthenticate, i.e. mid-attempt.
    Event::listen(Login::class, fn () => $this->travel(31)->seconds());
    $tokenBefore = $user->fresh()->remember_token;
    $logouts = 0;
    Event::listen(Logout::class, function () use (&$logouts) {
        $logouts++;
    });

    $response = coLogin($this, $user->email, 'password');

    $response->assertStatus(429);
    expect($response->headers->get('Retry-After'))->toBe('2');
    expect($response->headers->has('X-Locked-Until'))->toBeFalse();
    $this->assertGuest();
    expect(coRow($user->email)->failed_count)->toBe(2);
    expect($user->fresh()->remember_token)->toBe($tokenBefore);
    expect($logouts)->toBe(0);
});

test('over HTTP: without the lease problem the same login succeeds and clears', function () {
    $user = User::factory()->create();
    coLogin($this, $user->email, 'wrong-password');

    coLogin($this, $user->email, 'password')->assertRedirect();
    $this->assertAuthenticatedAs($user);
    expect(coRow($user->email))->toBeNull();
});

test('the stage undoes a challenge it issued when the lease was lost, and restores the earlier one', function () {
    $request = Request::create('/login', 'POST', ['email' => CO_EMAIL, 'password' => 'x'], server: ['REMOTE_ADDR' => CO_IP]);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('login.id', 41);
    coSeed(1);

    $returned = app(EnforceLoginLockout::class)->handle($request, function () use ($request) {
        coStealLease();
        $request->session()->put('login.id', 99); // this request's own challenge

        return new Response('challenge');
    });

    expect($returned->getStatusCode())->toBe(429)
        ->and($returned->headers->get('Retry-After'))->toBe('2')
        ->and($request->session()->get('login.id'))->toBe(41)
        ->and(coRow()->failed_count)->toBe(1);
});

// ===================================== 2. evidence belongs to this request

test('over HTTP: a pending 2FA challenge from an earlier request is not success evidence for a failed attempt', function () {
    $twoFactor = User::factory()->withTwoFactor()->create();
    $other = User::factory()->create();

    coLogin($this, $twoFactor->email, 'password')->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
    $this->assertSame($twoFactor->id, session('login.id'));

    // A failed attempt in the SAME session: counted, never cleared, nobody authenticated.
    coLogin($this, $other->email, 'wrong-password');
    coLogin($this, $other->email, 'wrong-password');

    expect(coRow($other->email)->failed_count)->toBe(2);
    $this->assertGuest();
    // Fortify's own behaviour for a failed attempt is unchanged: the earlier challenge is still pending.
    $this->assertSame($twoFactor->id, session('login.id'));
});

test('over HTTP: the pending challenge does not clear failures of the scope that fails next', function () {
    $twoFactor = User::factory()->withTwoFactor()->create();
    $plain = User::factory()->create();

    coLogin($this, $plain->email, 'wrong-password');
    coLogin($this, $twoFactor->email, 'password')->assertRedirect(route('two-factor.login'));

    coLogin($this, $plain->email, 'wrong-password');

    expect(coRow($plain->email)->failed_count)->toBe(2);
});

test('over HTTP: the confirmed-2FA flow still clears at the challenge and still completes with a code', function () {
    $user = User::factory()->withTwoFactor()->create();
    coLogin($this, $user->email, 'wrong-password');

    coLogin($this, $user->email, 'password')
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);
    expect(coRow($user->email))->toBeNull();
    $this->assertGuest();
});

// ========================= 3. malformed input at the throttle boundary

test('over HTTP: non-string email values are validation errors, never a server error, and touch no lockout state', function (mixed $email) {
    coSeed(2, email: 'victim@example.com');

    $browser = $this->withServerVariables(['REMOTE_ADDR' => CO_IP])
        ->post(route('login.store'), ['email' => $email, 'password' => 'x']);
    $browser->assertStatus(302)->assertSessionHasErrors('email');

    RateLimiter::clear(md5('login'.Str::transliterate('[malformed]|'.CO_IP)));

    $json = $this->withServerVariables(['REMOTE_ADDR' => CO_IP])
        ->postJson(route('login.store'), ['email' => $email, 'password' => 'x']);
    $json->assertStatus(422)->assertJsonValidationErrors('email');

    expect(LoginLockout::count())->toBe(1)
        ->and(coRow('victim@example.com')->failed_count)->toBe(2);
})->with([
    'array' => [['a@example.com']],
    'nested array' => [['a' => ['b' => 'c']]],
    'integer' => [12345],
    'boolean' => [true],
    'empty array' => [[]],
]);

test('over HTTP: a non-string password is a validation error and never counted', function () {
    $user = User::factory()->create();

    $this->withServerVariables(['REMOTE_ADDR' => CO_IP])
        ->postJson(route('login.store'), ['email' => $user->email, 'password' => ['x']])
        ->assertStatus(422)->assertJsonValidationErrors('password');

    expect(LoginLockout::count())->toBe(0);
});

test('over HTTP: malformed requests still spend the per-IP request budget', function () {
    config(['auth-security.login.ip_max_requests' => 3, 'auth-security.login.ip_window_seconds' => 60]);
    $statuses = [];

    foreach (range(1, 5) as $i) {
        $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => CO_IP])
            ->postJson(route('login.store'), ['email' => [$i], 'password' => 'x'])
            ->getStatusCode();
    }

    expect($statuses)->toBe([422, 422, 422, 429, 429]);
    expect(LoginLockout::count())->toBe(0);
});

test('over HTTP: the IP budget is also spent when a well-formed request follows malformed ones', function () {
    config(['auth-security.login.ip_max_requests' => 2, 'auth-security.login.ip_window_seconds' => 60]);
    $user = User::factory()->create();

    $this->withServerVariables(['REMOTE_ADDR' => CO_IP])->postJson(route('login.store'), ['email' => [1], 'password' => 'x']);
    $this->withServerVariables(['REMOTE_ADDR' => CO_IP])->postJson(route('login.store'), ['email' => [2], 'password' => 'x']);

    $this->withServerVariables(['REMOTE_ADDR' => CO_IP])
        ->postJson(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(429);
    expect(LoginLockout::count())->toBe(0);
});

test('the lockout stage fails closed on a non-string email instead of skipping the lockout', function () {
    $request = Request::create('/login', 'POST', ['email' => ['x'], 'password' => 'x'], server: ['REMOTE_ADDR' => CO_IP]);
    $reached = false;

    expect(fn () => app(EnforceLoginLockout::class)->handle($request, function () use (&$reached) {
        $reached = true;

        return new Response('next');
    }))->toThrow(RuntimeException::class);

    expect($reached)->toBeFalse()->and(LoginLockout::count())->toBe(0);
});

test('an expired lock row does not swallow a failure: it is replaced by a fresh window', function () {
    coSeed(3, now()->getTimestamp() - 1)->forceFill(['window_started_at' => now()->getTimestamp() - 2000])->save();

    expect(fn () => coSvc()->run(CO_EMAIL, CO_IP, fn () => throw coWrong()))->toThrow(ValidationException::class);

    expect(coRow()->failed_count)->toBe(1)->and(coRow()->locked_until)->toBeNull();
});

test('a lock set behind our back during a verified attempt is a lost decision: not admitted, lock kept', function () {
    expect(fn () => coSvc()->run(CO_EMAIL, CO_IP, function () {
        coSeed(3, now()->getTimestamp() + 1800);

        return 'verified';
    }))->toThrow(LoginLeaseLostException::class);

    expect(coRow()->locked_until)->toBe(now()->getTimestamp() + 1800);
});

test('a success never erases failures another request added while it was in flight', function () {
    $row = coSeed(1);

    expect(coSvc()->run(CO_EMAIL, CO_IP, function () use ($row) {
        LoginLockout::query()->whereKey($row->getKey())->increment('failed_count'); // B's failure

        return 'verified';
    }))->toBe('verified');

    expect(coRow()->failed_count)->toBe(2);
});

// ============================================== 1c. prune versus renewal

test('a row selected as prunable and then renewed by a request is not deleted', function () {
    $old = coSeed(2);
    $old->forceFill(['window_started_at' => now()->getTimestamp() - 901])->save(); // dead window

    $selected = (new LoginLockout)->prunable()->pluck('id')->all();
    expect($selected)->toBe([$old->getKey()]);

    // A failure arrives before the pruner's DELETE runs: the dead row is replaced by a fresh window.
    expect(fn () => coSvc()->run(CO_EMAIL, CO_IP, fn () => throw coWrong()))->toThrow(ValidationException::class);
    $fresh = coRow();
    expect($fresh->failed_count)->toBe(1);

    (new LoginLockout)->pruneAll();

    expect(coRow()?->getKey())->toBe($fresh->getKey())->and(coRow()->failed_count)->toBe(1);
});

test('a row selected as prunable and then LOCKED by a request is not deleted', function () {
    $old = coSeed(2);
    $old->forceFill(['window_started_at' => now()->getTimestamp() - 901])->save();
    expect((new LoginLockout)->prunable()->count())->toBe(1);

    // The row is revived in place (lock established) between selection and delete.
    LoginLockout::query()->whereKey($old->getKey())->update(['locked_until' => now()->getTimestamp() + 1800]);

    (new LoginLockout)->pruneAll();

    expect(coRow()->locked_until)->toBe(now()->getTimestamp() + 1800);
});

test('over HTTP: a lost lease with remember-me leaves no remember cookie behind', function () {
    $user = User::factory()->create();
    coLogin($this, $user->email, 'wrong-password');
    Event::listen(Login::class, fn () => $this->travel(31)->seconds());
    $tokenBefore = $user->fresh()->remember_token;
    $logouts = 0;
    Event::listen(Logout::class, function () use (&$logouts) {
        $logouts++;
    });

    coClearThrottles($user->email, CO_IP);
    $response = $this->withServerVariables(['REMOTE_ADDR' => CO_IP])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => 'on']);

    $response->assertStatus(429);
    $this->assertGuest();
    $recaller = Auth::guard('web')->getRecallerName();
    expect(collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $recaller && $c->getValue() !== ''))->toBeNull();
    expect($user->fresh()->remember_token)->toBe($tokenBefore);
    expect($logouts)->toBe(0);
});

test('a login created by an earlier stage and followed by an unrelated error is taken back', function () {
    $user = User::factory()->create();
    $request = Request::create('/login', 'POST', ['email' => CO_EMAIL, 'password' => 'x'], server: ['REMOTE_ADDR' => CO_IP]);
    $request->setLaravelSession(app('session.store'));

    $returned = app(EnforceLoginLockout::class)->handle($request, function () use ($user) {
        Auth::login($user); // AttemptToAuthenticate ran...

        return (new Response('boom', 500))->withException(new RuntimeException('LoginResponse failed')); // ...a later stage failed
    });

    expect($returned->getStatusCode())->toBe(500);
    $this->assertGuest();
});
