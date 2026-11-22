<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceLoginLockout;
use App\Models\Auth\LoginLockout;
use App\Models\User;
use App\Services\Auth\LoginLockoutService;
use Carbon\Carbon;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

/**
 * Phase 3: failed-password lockout (scope = normalized email + IP), over HTTP.
 *
 * Each mechanism is tested on its own. The two request throttles of Phase 2
 * (IP budget, and 5/min per email+IP — the SAME scope as the lockout) would
 * otherwise mask the lockout, so lkAttempt() clears both buckets before every
 * request; the tests that are about those throttles use lkRaw() instead.
 * Time is frozen and moved with travel(); nothing sleeps.
 *
 * Defaults under test: 3 failures within 900 s lock for 1800 s.
 */
const LK_IP = '203.0.113.20';

function lkClearThrottles(string $email, string $ip): void
{
    RateLimiter::clear(md5('login'.Str::transliterate(Str::lower(trim($email)).'|'.$ip)));
    RateLimiter::clear(md5('login'.'ip:'.$ip));
}

/**
 * @param  array<string, string>  $headers
 */
function lkRaw(mixed $test, string $email, string $password, string $ip = LK_IP, array $headers = []): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders($headers)
        ->post(route('login.store'), ['email' => $email, 'password' => $password]);
}

/**
 * @param  array<string, string>  $headers
 */
function lkAttempt(mixed $test, string $email, string $password, string $ip = LK_IP, array $headers = []): TestResponse
{
    lkClearThrottles($email, $ip);

    return lkRaw($test, $email, $password, $ip, $headers);
}

function lkFailOnce(mixed $test, string $email, string $ip = LK_IP): TestResponse
{
    return lkAttempt($test, $email, 'wrong-password', $ip);
}

function lkRow(string $email, string $ip = LK_IP): ?LoginLockout
{
    return LoginLockout::query()
        ->where('scope_hash', app(LoginLockoutService::class)->scopeHash($email, $ip))
        ->first();
}

function lkIso(int $timestamp): string
{
    return Carbon::createFromTimestampUTC($timestamp)->toIso8601ZuluString();
}

beforeEach(function () {
    $this->freezeTime();
    config(['auth-security.login.ip_max_requests' => 1000]);
});

// ------------------------------------------------------------ the threshold

test('failures one and two are ordinary authentication failures, and are stored', function () {
    $user = User::factory()->create();

    lkFailOnce($this, $user->email)->assertStatus(302)->assertSessionHasErrors('email');
    expect(lkRow($user->email)->failed_count)->toBe(1);
    expect(lkRow($user->email)->locked_until)->toBeNull();

    lkFailOnce($this, $user->email)->assertStatus(302)->assertSessionHasErrors('email');
    expect(lkRow($user->email)->failed_count)->toBe(2);
    expect(lkRow($user->email)->locked_until)->toBeNull();

    $this->assertGuest();
});

test('the third failure answers 429 and establishes the configured lockout', function () {
    $user = User::factory()->create();
    $start = now()->getTimestamp();

    lkFailOnce($this, $user->email);
    lkFailOnce($this, $user->email);
    $third = lkFailOnce($this, $user->email);

    $third->assertStatus(429);
    // The failure the pipeline had already flashed for its (replaced) redirect
    // must not resurface on the next page.
    $third->assertSessionMissing('errors');
    $third->assertSessionMissing('_old_input');
    expect($third->headers->get('Retry-After'))->toBe('1800');
    expect($third->headers->get('X-Locked-Until'))->toBe(lkIso($start + 1800));
    expect($third->headers->get('X-Server-Time'))->toBe(lkIso($start));

    $row = lkRow($user->email);
    expect($row->failed_count)->toBe(3);
    expect($row->locked_until)->toBe($start + 1800);
    $this->assertGuest();
});

test('during the lockout a correct and a wrong password are refused and nothing is verified', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $_) {
        lkFailOnce($this, $user->email);
    }
    $before = lkRow($user->email)->only(['failed_count', 'locked_until']);

    Event::fake([Failed::class, Login::class]);

    foreach (['password', 'wrong-password'] as $password) {
        lkAttempt($this, $user->email, $password)->assertStatus(429);
        $this->assertGuest();
    }

    // Failed / Login are fired only by a password verification: none happened.
    Event::assertNotDispatched(Failed::class);
    Event::assertNotDispatched(Login::class);
    expect(lkRow($user->email)->only(['failed_count', 'locked_until']))->toBe($before);
});

test('repeated blocked requests keep the exact expiration', function () {
    $user = User::factory()->create();
    $start = now()->getTimestamp();
    foreach (range(1, 3) as $_) {
        lkFailOnce($this, $user->email);
    }

    foreach ([300, 400, 500] as $elapsedStep) {
        $this->travel($elapsedStep)->seconds();
        $elapsed = now()->getTimestamp() - $start;

        $blocked = lkAttempt($this, $user->email, 'password');

        $blocked->assertStatus(429);
        expect($blocked->headers->get('X-Locked-Until'))->toBe(lkIso($start + 1800));
        expect($blocked->headers->get('Retry-After'))->toBe((string) (1800 - $elapsed));
        expect(lkRow($user->email)->locked_until)->toBe($start + 1800);
    }
});

// ------------------------------------------------------------------ windows

test('a failure window that ends before the threshold resets the sequence', function () {
    $user = User::factory()->create();

    lkFailOnce($this, $user->email);
    $this->travel(10)->seconds();
    lkFailOnce($this, $user->email);
    expect(lkRow($user->email)->failed_count)->toBe(2);

    $this->travel(890)->seconds(); // 900 s after the FIRST failure
    lkFailOnce($this, $user->email)->assertStatus(302);

    $row = lkRow($user->email);
    expect($row->failed_count)->toBe(1);
    expect($row->locked_until)->toBeNull();
    expect($row->window_started_at)->toBe(now()->getTimestamp());
});

test('the window starts at the first failure and later failures do not extend it', function () {
    $user = User::factory()->create();

    lkFailOnce($this, $user->email);
    $this->travel(450)->seconds();
    lkFailOnce($this, $user->email)->assertStatus(302);
    $this->travel(449)->seconds(); // 899 s after the first failure: still inside

    lkFailOnce($this, $user->email)->assertStatus(429);
});

test('the lock ends exactly at locked_until and a correct password works again', function () {
    $user = User::factory()->withRole('author')->create();
    foreach (range(1, 3) as $_) {
        lkFailOnce($this, $user->email);
    }

    $this->travel(1799)->seconds();
    $stillLocked = lkAttempt($this, $user->email, 'password');
    $stillLocked->assertStatus(429);
    expect($stillLocked->headers->get('Retry-After'))->toBe('1');
    $this->assertGuest();

    $this->travel(1)->seconds(); // now == locked_until: open
    lkAttempt($this, $user->email, 'password')->assertRedirect(route('author.dashboard'));
    $this->assertAuthenticatedAs($user);
    expect(lkRow($user->email))->toBeNull();
});

test('after the lock expires the failure sequence starts again from one', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $_) {
        lkFailOnce($this, $user->email);
    }

    $this->travel(1800)->seconds();
    lkFailOnce($this, $user->email)->assertStatus(302)->assertSessionHasErrors('email');

    $row = lkRow($user->email);
    expect($row->failed_count)->toBe(1);
    expect($row->locked_until)->toBeNull();
});

// ------------------------------------------------------------------ success

test('a successful password clears earlier failures', function () {
    $user = User::factory()->withRole('author')->create();

    lkFailOnce($this, $user->email);
    lkFailOnce($this, $user->email);
    lkAttempt($this, $user->email, 'password')->assertRedirect(route('author.dashboard'));
    expect(lkRow($user->email))->toBeNull();

    $this->post(route('logout'));

    lkFailOnce($this, $user->email);
    lkFailOnce($this, $user->email)->assertStatus(302); // not locked: the count restarted
    expect(lkRow($user->email)->failed_count)->toBe(2);
});

test('a confirmed-2FA user follows the same password rules, and reaching the challenge clears', function () {
    $user = User::factory()->withTwoFactor()->create();

    // Two failures, then the correct password: the challenge redirect clears.
    lkFailOnce($this, $user->email);
    lkFailOnce($this, $user->email);
    lkAttempt($this, $user->email, 'password')
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);
    expect(lkRow($user->email))->toBeNull();
    $this->assertGuest();

    // Three failures lock; a correct password is refused (no challenge).
    foreach (range(1, 3) as $index) {
        $response = lkFailOnce($this, $user->email);
        $index === 3 ? $response->assertStatus(429) : $response->assertStatus(302);
    }
    $blocked = lkAttempt($this, $user->email, 'password');
    $blocked->assertStatus(429);
    expect($blocked->headers->get('Location'))->toBeNull();
    $this->assertGuest();

    $this->travel(1800)->seconds();
    lkAttempt($this, $user->email, 'password')->assertRedirect(route('two-factor.login'));
    expect(lkRow($user->email))->toBeNull();
});

// ------------------------------------------------------------ unknown email

test('an unknown email follows exactly the same counting and response contract', function () {
    $known = User::factory()->create();
    $unknown = 'nobody@example.com';
    $shape = fn (TestResponse $r): array => [
        $r->getStatusCode(),
        $r->headers->get('Retry-After'),
        $r->headers->has('X-Locked-Until'),
        $r->getStatusCode() === 429 ? $r->getContent() : null,
    ];

    $knownShapes = $unknownShapes = [];
    foreach (range(1, 4) as $_) {
        $knownShapes[] = $shape(lkFailOnce($this, $known->email, '203.0.113.31'));
        $unknownShapes[] = $shape(lkFailOnce($this, $unknown, '203.0.113.32'));
    }

    expect($unknownShapes)->toBe($knownShapes);
    expect(array_column($unknownShapes, 0))->toBe([302, 302, 429, 429]);
    expect(lkRow($unknown, '203.0.113.32')->failed_count)->toBe(3);
});

// -------------------------------------------------- scope, normalization, config

test('another email or another IP is an independent scope', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    foreach (range(1, 3) as $_) {
        lkFailOnce($this, $a->email);
    }

    lkFailOnce($this, $b->email)->assertStatus(302);                  // same IP, other email
    lkFailOnce($this, $a->email, '203.0.113.99')->assertStatus(302);  // same email, other IP
    expect(lkRow($b->email)->failed_count)->toBe(1);
    expect(lkRow($a->email, '203.0.113.99')->failed_count)->toBe(1);
    lkFailOnce($this, $a->email)->assertStatus(429);
});

test('email case and surrounding spaces do not create separate scopes', function () {
    foreach (['Mixed.Case@Example.COM', '  mixed.case@example.com  ', 'MIXED.CASE@example.com'] as $index => $variant) {
        $response = lkFailOnce($this, $variant);
        $index < 2 ? $response->assertStatus(302) : $response->assertStatus(429);
    }

    expect(LoginLockout::count())->toBe(1);
    expect(lkRow('mixed.case@example.com')->failed_count)->toBe(3);
});

test('spellings the database treats as the same account cannot be rotated to dodge the lockout', function () {
    // MySQL's utf8mb4_unicode_ci matches these to user@example.com; the scope
    // must not depend on the spelling.
    $variants = ['collide@example.com', "coll\u{00ED}de@example.com", "COLL\u{00CD}DE@EXAMPLE.COM"];

    foreach ($variants as $index => $variant) {
        $response = lkFailOnce($this, $variant);
        $index < 2 ? $response->assertStatus(302) : $response->assertStatus(429);
    }

    expect(LoginLockout::count())->toBe(1);
});

test('the thresholds and durations come from config', function () {
    config([
        'auth-security.login.max_failures' => 2,
        'auth-security.login.failure_window_seconds' => 100,
        'auth-security.login.lockout_seconds' => 60,
    ]);
    $user = User::factory()->create();

    lkFailOnce($this, $user->email)->assertStatus(302);
    $second = lkFailOnce($this, $user->email);
    $second->assertStatus(429);
    expect($second->headers->get('Retry-After'))->toBe('60');

    $this->travel(60)->seconds();
    lkFailOnce($this, $user->email)->assertStatus(302); // lock over, count restarts

    $this->travel(101)->seconds(); // the 100 s window ended
    lkFailOnce($this, $user->email)->assertStatus(302);
    expect(lkRow($user->email)->failed_count)->toBe(1);
});

// ------------------------------------- what the lockout must not touch

test('an existing authenticated session stays active while the scope is locked', function () {
    $user = User::factory()->withRole('author')->create();
    foreach (range(1, 3) as $_) {
        lkFailOnce($this, $user->email);
    }

    $this->actingAs($user)->get(route('author.dashboard'))->assertOk();
});

test('passkey login options stay available while the password scope is locked', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $_) {
        lkFailOnce($this, $user->email);
    }

    $this->withServerVariables(['REMOTE_ADDR' => LK_IP])
        ->getJson(route('passkey.login-options'))
        ->assertOk();
    expect(LoginLockout::count())->toBe(1);
});

test('failures at the second-factor challenge never enter the password counter', function () {
    $user = User::factory()->withTwoFactor()->create();

    lkAttempt($this, $user->email, 'password')->assertRedirect(route('two-factor.login'));

    foreach (['bad-1', 'bad-2', 'bad-3', 'bad-4'] as $code) {
        $this->withServerVariables(['REMOTE_ADDR' => LK_IP])
            ->post(route('two-factor.login'), ['recovery_code' => $code])
            ->assertSessionHasErrors();
    }
    expect(LoginLockout::count())->toBe(0);

    $this->withServerVariables(['REMOTE_ADDR' => LK_IP])
        ->post(route('two-factor.login'), ['recovery_code' => 'recovery-code-1']);
    $this->assertAuthenticatedAs($user);
    expect(LoginLockout::count())->toBe(0);
});

// ----------------------------- request throttles and malformed requests

test('a request rejected by the IP request budget is not a password failure', function () {
    config(['auth-security.login.ip_max_requests' => 1, 'auth-security.login.ip_window_seconds' => 60]);
    $user = User::factory()->create();

    lkAttempt($this, $user->email, 'wrong-password')->assertStatus(302);
    expect(lkRow($user->email)->failed_count)->toBe(1);

    $throttled = lkRaw($this, $user->email, 'wrong-password');
    $throttled->assertStatus(429);
    expect($throttled->headers->has('X-Locked-Until'))->toBeFalse();
    // A request throttle never carries the lockout/busy discriminator.
    expect($throttled->headers->has('X-Login-Reason'))->toBeFalse();
    expect($throttled->headers->get('Retry-After'))->not->toBeNull();
    expect($throttled->headers->get('X-RateLimit-Limit'))->not->toBeNull();
    expect(lkRow($user->email)->failed_count)->toBe(1);
});

test('a request rejected by the email+IP request limiter is not a password failure', function () {
    // Lockout out of the way, so the 5/min request limiter is what answers.
    config(['auth-security.login.max_failures' => 100]);
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        lkRaw($this, $user->email, 'wrong-password')->assertStatus(302);
    }
    expect(lkRow($user->email)->failed_count)->toBe(5);

    $throttled = lkRaw($this, $user->email, 'wrong-password');
    $throttled->assertStatus(429);
    expect($throttled->headers->has('X-Locked-Until'))->toBeFalse();
    expect($throttled->headers->has('X-Login-Reason'))->toBeFalse();
    expect($throttled->headers->get('Retry-After'))->not->toBeNull();
    expect($throttled->headers->get('X-RateLimit-Limit'))->not->toBeNull();
    expect(lkRow($user->email)->failed_count)->toBe(5);
});

test('malformed requests are validation errors and never password failures', function () {
    $this->withServerVariables(['REMOTE_ADDR' => LK_IP]);

    $this->post(route('login.store'), ['email' => 'a@example.com'])->assertSessionHasErrors('password');
    $this->post(route('login.store'), ['password' => 'x'])->assertSessionHasErrors('email');
    $this->post(route('login.store'), [])->assertSessionHasErrors(['email', 'password']);

    expect(LoginLockout::count())->toBe(0);
});

// ------------------------------------------------------- response contract

test('a JSON client gets the lockout in the body and the headers', function () {
    $user = User::factory()->create();
    $start = now()->getTimestamp();
    foreach (range(1, 2) as $_) {
        lkFailOnce($this, $user->email);
    }
    lkClearThrottles($user->email, LK_IP);

    $response = $this->withServerVariables(['REMOTE_ADDR' => LK_IP])
        ->postJson(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    $response->assertStatus(429)
        ->assertExactJson([
            'message' => __('lockout.locked'),
            'errors' => ['email' => [__('lockout.locked')]],
            'locked_until' => lkIso($start + 1800),
            'server_time' => lkIso($start),
            'retry_after' => 1800,
            'reason' => 'locked',
        ])
        ->assertHeader('X-Login-Reason', 'locked')
        ->assertHeader('Retry-After', '1800')
        ->assertHeader('X-Locked-Until', lkIso($start + 1800))
        ->assertHeader('X-Server-Time', lkIso($start));

    // No hint about the account in the body.
    expect($response->getContent())->not->toContain($user->email);
});

test('a JSON client refused because the scope mutex is busy gets reason busy and the short retry', function () {
    $user = User::factory()->create();
    config(['auth-security.lockout.lock_wait_seconds' => 0]);

    $held = Cache::store('database')->lock('login-lockout:'.app(LoginLockoutService::class)->scopeHash($user->email, LK_IP), 30);
    expect($held->get())->toBeTrue();
    lkClearThrottles($user->email, LK_IP);

    $response = $this->withServerVariables(['REMOTE_ADDR' => LK_IP])
        ->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $held->release();

    $response->assertStatus(429)
        ->assertExactJson([
            'message' => __('lockout.busy'),
            'errors' => ['email' => [__('lockout.busy')]],
            'retry_after' => 2,
            'reason' => 'busy',
        ])
        ->assertHeader('Retry-After', '2')
        ->assertHeader('X-Login-Reason', 'busy');
    expect($response->headers->has('X-Locked-Until'))->toBeFalse();
});

test('a lockout with a non-default duration reports that duration and the locked reason', function () {
    config(['auth-security.login.lockout_seconds' => 420]);
    $user = User::factory()->create();
    $start = now()->getTimestamp();

    lkFailOnce($this, $user->email)->assertStatus(302);
    lkFailOnce($this, $user->email)->assertStatus(302);
    $third = lkFailOnce($this, $user->email);

    $third->assertStatus(429);
    expect($third->headers->get('X-Login-Reason'))->toBe('locked');
    expect($third->headers->get('Retry-After'))->toBe('420');
    expect($third->headers->get('X-Locked-Until'))->toBe(lkIso($start + 420));

    $this->travel(20)->seconds();
    $blocked = lkAttempt($this, $user->email, 'password');

    $blocked->assertStatus(429);
    expect($blocked->headers->get('X-Login-Reason'))->toBe('locked');
    expect($blocked->headers->get('Retry-After'))->toBe('400');
    expect($blocked->headers->get('X-Locked-Until'))->toBe(lkIso($start + 420));
});

test('the Inertia login form receives a plain 429 with the countdown headers', function () {
    $user = User::factory()->create();
    $start = now()->getTimestamp();
    foreach (range(1, 3) as $_) {
        lkFailOnce($this, $user->email);
    }

    // What the Inertia <Form> sends: an XHR with a browser Accept header.
    $response = lkAttempt($this, $user->email, 'wrong-password', LK_IP, [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ]);

    $response->assertStatus(429);
    expect($response->headers->get('X-Inertia'))->toBeNull();                 // not an Inertia page: Login.vue's httpException handler takes it
    expect($response->headers->get('Content-Type'))->toContain('text/plain');
    expect($response->getContent())->toBe(__('lockout.locked'));
    expect($response->headers->get('Retry-After'))->toBe('1800');
    expect($response->headers->get('X-Locked-Until'))->toBe(lkIso($start + 1800));
});

test('the lockout message follows the locale cookie', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $_) {
        lkFailOnce($this, $user->email);
    }
    lkClearThrottles($user->email, LK_IP);

    $response = $this->withServerVariables(['REMOTE_ADDR' => LK_IP])
        ->withUnencryptedCookie('locale', 'fr')
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'x']);

    $response->assertStatus(429);
    expect($response->getContent())->toBe('Trop de tentatives de connexion échouées. Réessayez plus tard.');
});

// ---------------------------------------------------------- pipeline wiring

test('the login pipeline keeps Fortify\'s own stages and puts the lockout before both password checks', function () {
    $stages = (Fortify::$authenticateThroughCallback)(request());

    expect(array_values($stages))->toBe([
        CanonicalizeUsername::class,
        EnforceLoginLockout::class,
        RedirectsIfTwoFactorAuthenticatable::class,
        AttemptToAuthenticate::class,
        PrepareAuthenticatedSession::class,
    ]);

    // Fortify's own condition for its throttle stage is preserved too.
    config(['fortify.limiters.login' => null]);
    expect(array_values((Fortify::$authenticateThroughCallback)(request()))[0])->toBe(EnsureLoginIsNotThrottled::class);
});

test('a failure is counted exactly once per failed request, for both verification paths', function () {
    $user = User::factory()->create();             // no 2FA: AttemptToAuthenticate would fail it second
    $twoFactor = User::factory()->withTwoFactor()->create();

    lkFailOnce($this, $user->email);
    lkFailOnce($this, $twoFactor->email);
    lkFailOnce($this, 'unknown@example.com');

    expect(lkRow($user->email)->failed_count)->toBe(1);
    expect(lkRow($twoFactor->email)->failed_count)->toBe(1);
    expect(lkRow('unknown@example.com')->failed_count)->toBe(1);
});

// ------------------------------------------- review follow-ups (Meriem)

test('when the scope mutex is busy the answer is a generic 429 and a correct password is not even checked', function () {
    $user = User::factory()->withRole('author')->create();
    config(['auth-security.lockout.lock_wait_seconds' => 0]);

    $held = Cache::store('database')->lock('login-lockout:'.app(LoginLockoutService::class)->scopeHash($user->email, LK_IP), 30);
    expect($held->get())->toBeTrue();
    Event::fake([Failed::class, Login::class]);

    $response = lkAttempt($this, $user->email, 'password');

    $response->assertStatus(429);
    expect($response->headers->get('Retry-After'))->toBe('2');
    expect($response->headers->get('X-Login-Reason'))->toBe('busy');
    expect($response->headers->has('X-Locked-Until'))->toBeFalse();
    expect($response->getContent())->toBe(__('lockout.busy'));
    $this->assertGuest();
    Event::assertNotDispatched(Login::class);
    Event::assertNotDispatched(Failed::class);
    expect(LoginLockout::count())->toBe(0);

    $held->release();
});

test('a user whose 2FA secret is not yet confirmed still counts a failure exactly once', function () {
    // Fortify (confirm=true) then skips the challenge branch, so the password is
    // verified by the second stage: the count must still be one per request.
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => null,
    ]);

    lkFailOnce($this, $user->email);

    expect(lkRow($user->email)->failed_count)->toBe(1);
});
