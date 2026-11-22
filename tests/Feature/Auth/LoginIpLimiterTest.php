<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Phase 2: the independent per-IP request budget on POST /login, the
 * centralised config/auth-security.php, and the trusted-proxy default.
 *
 * Every test pins the IP budget itself (config overrides) and uses a DIFFERENT
 * email per request unless it is testing the email+IP limiter, so the older
 * limiter (5 per minute per email+IP) can never be what blocks the request.
 * Time is moved with travel(); nothing sleeps.
 */
const IP_LIMITER_ENV_KEYS = [
    'AUTH_LOGIN_MAX_FAILURES',
    'AUTH_LOGIN_FAILURE_WINDOW_SECONDS',
    'AUTH_LOGIN_LOCKOUT_SECONDS',
    'AUTH_LOGIN_IP_MAX_REQUESTS',
    'AUTH_LOGIN_IP_WINDOW_SECONDS',
    'AUTH_TRUSTED_PROXIES',
];

function ipBudget(int $max, int $windowSeconds): void
{
    config([
        'auth-security.login.ip_max_requests' => $max,
        'auth-security.login.ip_window_seconds' => $windowSeconds,
    ]);
}

/**
 * @param  array<string, string>  $headers
 */
function loginFrom(mixed $test, string $ip, string $email, string $password = 'wrong-password', array $headers = []): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders($headers)
        ->post(route('login.store'), ['email' => $email, 'password' => $password]);
}

/**
 * Runs $callback with exactly these auth env values (all other AUTH_* keys
 * unset), whatever the developer's own .env holds, then restores everything.
 * env() reads $_ENV, $_SERVER and getenv(), so all three are driven.
 *
 * @param  array<string, string>  $vars
 */
function withAuthEnv(array $vars, Closure $callback): mixed
{
    $saved = [];

    foreach (IP_LIMITER_ENV_KEYS as $key) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }

    foreach ($vars as $key => $value) {
        $_SERVER[$key] = $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        return $callback();
    } finally {
        foreach ($saved as $key => [$server, $env, $process]) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            $server === null || $_SERVER[$key] = $server;
            $env === null || $_ENV[$key] = $env;
            $process === false || putenv("{$key}={$process}");
        }
    }
}

// ------------------------------------------------------------- the budget

test('different submitted emails from one IP share the IP budget', function () {
    ipBudget(3, 60);

    foreach (['one@example.com', 'two@example.com', 'three@example.com'] as $email) {
        loginFrom($this, '203.0.113.10', $email)->assertSessionHasErrors('email'); // a normal failed login, not a 429
    }

    loginFrom($this, '203.0.113.10', 'four@example.com')->assertStatus(429);
});

test('different IPs have independent budgets', function () {
    ipBudget(2, 60);

    loginFrom($this, '203.0.113.10', 'a@example.com');
    loginFrom($this, '203.0.113.10', 'b@example.com');
    loginFrom($this, '203.0.113.10', 'c@example.com')->assertStatus(429);

    loginFrom($this, '203.0.113.11', 'a@example.com')->assertSessionHasErrors('email');
    loginFrom($this, '203.0.113.11', 'b@example.com')->assertSessionHasErrors('email');
    loginFrom($this, '203.0.113.11', 'c@example.com')->assertStatus(429);
});

test('login-page GET requests do not consume the budget', function () {
    ipBudget(2, 60);

    foreach (range(1, 6) as $_) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get(route('login'))->assertOk();
    }

    loginFrom($this, '203.0.113.10', 'a@example.com')->assertSessionHasErrors('email');
    loginFrom($this, '203.0.113.10', 'b@example.com')->assertSessionHasErrors('email');
    loginFrom($this, '203.0.113.10', 'c@example.com')->assertStatus(429);
});

test('every submission counts, successful or not', function () {
    ipBudget(2, 60);
    $user = User::factory()->withRole('author')->create();

    loginFrom($this, '203.0.113.10', $user->email, 'password')->assertRedirect(route('author.dashboard'));
    $this->post(route('logout'));
    loginFrom($this, '203.0.113.10', 'nobody@example.com')->assertSessionHasErrors('email');

    loginFrom($this, '203.0.113.10', $user->email, 'password')->assertStatus(429);
    $this->assertGuest();
});

test('requests are allowed again once the window has passed', function () {
    ipBudget(2, 30);

    loginFrom($this, '203.0.113.10', 'a@example.com');
    loginFrom($this, '203.0.113.10', 'b@example.com');
    loginFrom($this, '203.0.113.10', 'c@example.com')->assertStatus(429);

    $this->travel(29)->seconds();
    loginFrom($this, '203.0.113.10', 'd@example.com')->assertStatus(429);

    $this->travel(2)->seconds(); // 31 s after the first request
    loginFrom($this, '203.0.113.10', 'e@example.com')->assertSessionHasErrors('email');
});

test('a blocked request does not extend the window', function () {
    ipBudget(1, 30);

    loginFrom($this, '203.0.113.10', 'a@example.com');

    $this->travel(20)->seconds();
    loginFrom($this, '203.0.113.10', 'b@example.com')->assertStatus(429);

    $this->travel(11)->seconds(); // 31 s after the ONE counted request
    loginFrom($this, '203.0.113.10', 'c@example.com')->assertSessionHasErrors('email');
});

test('the configured threshold and window both take effect', function () {
    ipBudget(4, 120);

    foreach (['a', 'b', 'c', 'd'] as $name) {
        loginFrom($this, '203.0.113.10', "{$name}@example.com")->assertSessionHasErrors('email');
    }
    loginFrom($this, '203.0.113.10', 'e@example.com')->assertStatus(429);

    $this->travel(61)->seconds(); // a 60 s window would be over; 120 s is not
    loginFrom($this, '203.0.113.10', 'f@example.com')->assertStatus(429);

    $this->travel(60)->seconds(); // 121 s
    loginFrom($this, '203.0.113.10', 'g@example.com')->assertSessionHasErrors('email');
});

// ------------------------------------------------ the blocked response

test('the blocked response is a 429 with retry information and reveals nothing about the account', function () {
    ipBudget(1, 45);
    $user = User::factory()->create();

    loginFrom($this, '203.0.113.10', 'warmup@example.com');

    $known = loginFrom($this, '203.0.113.10', $user->email, 'password');
    $unknown = loginFrom($this, '203.0.113.10', 'nobody@example.com', 'password');

    foreach ([$known, $unknown] as $response) {
        $response->assertStatus(429);
        expect((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(45);
        expect($response->headers->get('X-RateLimit-Limit'))->toBe('1');
        expect($response->headers->get('X-RateLimit-Remaining'))->toBe('0');
    }

    expect($known->getContent())->toBe($unknown->getContent());
    expect($known->getContent())->not->toContain($user->email);
    $this->assertGuest();
});

test('a JSON client also gets a 429 with Retry-After', function () {
    ipBudget(1, 45);

    loginFrom($this, '203.0.113.10', 'a@example.com');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->postJson(route('login.store'), ['email' => 'b@example.com', 'password' => 'x'])
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

// ------------------------------------ independence from the email+IP limiter

test('the email+IP limiter still blocks one email while the IP budget has room', function () {
    ipBudget(1000, 60);
    // Phase 3: keep the failed-password lockout out of the way, so it is the
    // 5-per-minute request limiter (the thing under test) that answers.
    config(['auth-security.login.max_failures' => 100]);

    foreach (range(1, 5) as $_) {
        loginFrom($this, '203.0.113.10', 'same@example.com')->assertSessionHasErrors('email');
    }

    loginFrom($this, '203.0.113.10', 'same@example.com')->assertStatus(429);

    // Another email from the same IP is untouched: the two buckets are separate.
    loginFrom($this, '203.0.113.10', 'other@example.com')->assertSessionHasErrors('email');
});

test('the two limits use separate cache keys', function () {
    ipBudget(1000, 60);

    loginFrom($this, '203.0.113.10', 'Same@Example.com');

    $emailKey = md5('login'.Str::transliterate('same@example.com|203.0.113.10'));
    $ipKey = md5('login'.'ip:203.0.113.10');

    expect($emailKey)->not->toBe($ipKey);
    expect(RateLimiter::attempts($emailKey))->toBe(1);
    expect(RateLimiter::attempts($ipKey))->toBe(1);
});

// -------------------------------------------------------------- IP source

test('a spoofed X-Forwarded-For from an untrusted peer cannot dodge the budget', function () {
    ipBudget(3, 60);

    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $i => $fake) {
        loginFrom($this, '203.0.113.10', "u{$i}@example.com", headers: [
            'X-Forwarded-For' => $fake,
            'X-Real-IP' => $fake,
            'Forwarded' => "for={$fake}",
        ])->assertSessionHasErrors('email');
    }

    loginFrom($this, '203.0.113.10', 'u9@example.com', headers: ['X-Forwarded-For' => '198.51.100.99'])->assertStatus(429);
});

test('by default no proxy is trusted', function () {
    expect(config('trustedproxy.proxies'))->toBe([]);
});

test('a peer that is a configured trusted proxy is believed, and only that peer', function () {
    ipBudget(1, 60);
    config(['trustedproxy.proxies' => ['10.0.0.1']]);

    // Through the trusted proxy, two real clients get two budgets...
    loginFrom($this, '10.0.0.1', 'a@example.com', headers: ['X-Forwarded-For' => '198.51.100.1'])->assertSessionHasErrors('email');
    loginFrom($this, '10.0.0.1', 'b@example.com', headers: ['X-Forwarded-For' => '198.51.100.2'])->assertSessionHasErrors('email');
    loginFrom($this, '10.0.0.1', 'c@example.com', headers: ['X-Forwarded-For' => '198.51.100.1'])->assertStatus(429);

    // ...while any other peer sending the same header is still counted as itself.
    loginFrom($this, '203.0.113.50', 'd@example.com', headers: ['X-Forwarded-For' => '198.51.100.7'])->assertSessionHasErrors('email');
    loginFrom($this, '203.0.113.50', 'e@example.com', headers: ['X-Forwarded-For' => '198.51.100.8'])->assertStatus(429);
});

test('trusted-proxy config refuses wildcards, wide ranges and junk', function () {
    $config = withAuthEnv([
        'AUTH_TRUSTED_PROXIES' => '*, **, REMOTE_ADDR, 0.0.0.0/0, 10.0.0.0/7, 2001:db8::/16, not-an-ip, 1.2.3.4/33, 10.1.2.3, 10.0.0.0/8 , 2001:db8::/32',
    ], fn () => require base_path('config/trustedproxy.php'));

    expect($config['proxies'])->toBe(['10.1.2.3', '10.0.0.0/8', '2001:db8::/32']);
    expect($config['rejected'])->toBe(8);

    $unset = withAuthEnv([], fn () => require base_path('config/trustedproxy.php'));
    expect($unset)->toBe(['proxies' => [], 'rejected' => 0]);
});

// ------------------------------------------------------- the configuration

test('the agreed defaults apply when nothing is set', function () {
    $config = withAuthEnv([], fn () => require base_path('config/auth-security.php'));

    expect($config)->toBe([
        'login' => [
            'max_failures' => 3,
            'failure_window_seconds' => 900,
            'lockout_seconds' => 1800,
            'ip_max_requests' => 20,
            'ip_window_seconds' => 60,
        ],
        'lockout' => [
            'lock_store' => 'database',
            'lock_wait_seconds' => 3,
            'lock_ttl_seconds' => 30,
        ],
        'invalid' => [],
    ]);
});

test('env values are read as whole numbers', function () {
    $config = withAuthEnv([
        'AUTH_LOGIN_MAX_FAILURES' => '5',
        'AUTH_LOGIN_FAILURE_WINDOW_SECONDS' => '600',
        'AUTH_LOGIN_LOCKOUT_SECONDS' => '3600',
        'AUTH_LOGIN_IP_MAX_REQUESTS' => '50',
        'AUTH_LOGIN_IP_WINDOW_SECONDS' => '120',
    ], fn () => require base_path('config/auth-security.php'));

    expect($config['login'])->toBe([
        'max_failures' => 5,
        'failure_window_seconds' => 600,
        'lockout_seconds' => 3600,
        'ip_max_requests' => 50,
        'ip_window_seconds' => 120,
    ]);
    expect($config['invalid'])->toBe([]);
});

test('invalid or non-positive values fall back to the default and are reported, never disabled', function (string $bad) {
    $config = withAuthEnv(
        ['AUTH_LOGIN_IP_MAX_REQUESTS' => $bad, 'AUTH_LOGIN_IP_WINDOW_SECONDS' => $bad],
        fn () => require base_path('config/auth-security.php'),
    );

    expect($config['login']['ip_max_requests'])->toBe(20);
    expect($config['login']['ip_window_seconds'])->toBe(60);
    expect($config['invalid'])->toBe(['AUTH_LOGIN_IP_MAX_REQUESTS', 'AUTH_LOGIN_IP_WINDOW_SECONDS']);
})->with(['zero' => '0', 'negative' => '-5', 'text' => 'abc', 'decimal' => '1.5', 'too large' => '99999999', 'blank' => '']);

test('a misconfigured login still enforces the default budget and names the bad keys in the log', function () {
    Log::spy();
    config([
        'auth-security.login.ip_max_requests' => 2, // what the fallback would have produced
        'auth-security.login.ip_window_seconds' => 60,
        'auth-security.invalid' => ['AUTH_LOGIN_IP_MAX_REQUESTS'],
    ]);

    loginFrom($this, '203.0.113.10', 'a@example.com');
    loginFrom($this, '203.0.113.10', 'b@example.com');
    loginFrom($this, '203.0.113.10', 'c@example.com')->assertStatus(429);

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $context === ['keys' => ['AUTH_LOGIN_IP_MAX_REQUESTS']],
    );
});

// ------------------------------------------- login flows within the budget

test('a normal login still works and lands on the role dashboard', function () {
    ipBudget(5, 60);
    $user = User::factory()->withRole('author')->create();

    loginFrom($this, '203.0.113.10', $user->email, 'password')->assertRedirect(route('author.dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('the two-factor redirect still works within the budget', function () {
    ipBudget(5, 60);
    $user = User::factory()->withTwoFactor()->create();

    loginFrom($this, '203.0.113.10', $user->email, 'password')
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();
});

test('passkey endpoints are not governed by the password-login budget', function () {
    ipBudget(1, 60);

    loginFrom($this, '203.0.113.10', 'a@example.com');
    loginFrom($this, '203.0.113.10', 'b@example.com')->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->getJson(route('passkey.login-options'))
        ->assertOk();
});
