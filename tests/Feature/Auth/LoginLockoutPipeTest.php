<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceLoginLockout;
use App\Models\Auth\LoginLockout;
use App\Models\User;
use App\Services\Auth\LoginLockoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The lockout pipeline stage with a controlled "rest of the pipeline".
 *
 * Fortify runs its stages through Illuminate\Routing\Pipeline, which renders a
 * stage's exception and returns the response with the exception attached
 * (Routing\Pipeline::handleException -> withException). The stage must read a
 * rejected password from that attached exception; these tests pin that
 * mechanism, and the plain "exception propagates" variant, directly.
 */
const PIPE_EMAIL = 'pipe@example.com';
const PIPE_IP = '198.51.100.77';

function pipeRequest(): Request
{
    return Request::create(
        '/login',
        'POST',
        ['email' => PIPE_EMAIL, 'password' => 'secret-should-never-be-stored'],
        server: ['REMOTE_ADDR' => PIPE_IP],
    );
}

function pipeRow(): ?LoginLockout
{
    return LoginLockout::query()
        ->where('scope_hash', app(LoginLockoutService::class)->scopeHash(PIPE_EMAIL, PIPE_IP))
        ->first();
}

function pipeSeed(int $failures): void
{
    LoginLockout::create([
        'scope_hash' => app(LoginLockoutService::class)->scopeHash(PIPE_EMAIL, PIPE_IP),
        'failed_count' => $failures,
        'window_started_at' => now()->getTimestamp(),
    ]);
}

function pipeStage(Request $request, Closure $next): mixed
{
    return app(EnforceLoginLockout::class)->handle($request, $next);
}

beforeEach(function () {
    $this->freezeTime();
});

test('a rejected password arriving as a rendered response is counted once and that response is returned', function () {
    $rendered = redirect('/login')->withException(ValidationException::withMessages(['email' => 'auth.failed']));

    $returned = pipeStage(pipeRequest(), fn () => $rendered);

    expect($returned)->toBe($rendered);
    expect(pipeRow()->failed_count)->toBe(1);
});

test('a rejected password arriving as a propagating exception is counted once and re-thrown', function () {
    expect(fn () => pipeStage(pipeRequest(), fn () => throw ValidationException::withMessages(['email' => 'auth.failed'])))
        ->toThrow(ValidationException::class);

    expect(pipeRow()->failed_count)->toBe(1);
});

function pipeRequestWithSession(array $session = []): Request
{
    $request = pipeRequest();
    $request->setLaravelSession(app('session.store'));
    $request->session()->put($session);

    return $request;
}

test('a clean response plus the 2FA challenge marker is a verified password and clears the state', function () {
    pipeSeed(2);
    $response = new Response('ok');

    $request = pipeRequestWithSession();

    // The challenge is issued BY this request (RedirectIfTwoFactorAuthenticatable).
    expect(pipeStage($request, function () use ($request, $response) {
        $request->session()->put('login.id', 1);

        return $response;
    }))->toBe($response);
    expect(pipeRow())->toBeNull();
});

test('a clean response plus an authenticated guard is a verified password and clears the state', function () {
    pipeSeed(2);
    $response = new Response('ok');
    $user = User::factory()->create();

    // AttemptToAuthenticate logs the user in DURING the pipeline.
    expect(pipeStage(pipeRequestWithSession(), function () use ($response, $user) {
        Auth::login($user);

        return $response;
    }))->toBe($response);
    expect(pipeRow())->toBeNull();
});

test('a guard that was ALREADY authenticated before the attempt is not evidence of a verified password', function () {
    pipeSeed(2);
    $response = new Response('some other response');
    Auth::login(User::factory()->create());

    expect(pipeStage(pipeRequestWithSession(), fn () => $response))->toBe($response);
    expect(pipeRow()->failed_count)->toBe(2);
});

test('a challenge left by an EARLIER request is not evidence, survives an inconclusive attempt, and is restored after a failure', function () {
    pipeSeed(1);
    $request = pipeRequestWithSession(['login.id' => 41, 'login.remember' => true]);

    // Inconclusive (a clean response with no new evidence): state untouched, old challenge intact.
    pipeStage($request, fn () => new Response('x'));
    expect(pipeRow()->failed_count)->toBe(1);
    expect($request->session()->get('login.id'))->toBe(41);
    expect($request->session()->get('login.remember'))->toBeTrue();

    // A failed password: counted once, the old challenge is still there afterwards.
    $rendered = redirect('/login')->withException(ValidationException::withMessages(['email' => 'auth.failed']));
    pipeStage($request, fn () => $rendered);
    expect(pipeRow()->failed_count)->toBe(2);
    expect($request->session()->get('login.id'))->toBe(41);
});

test('a NEW challenge issued by a verified attempt replaces the earlier one', function () {
    pipeSeed(1);
    $request = pipeRequestWithSession(['login.id' => 41]);

    pipeStage($request, function () use ($request) {
        $request->session()->put('login.id', 99);

        return new Response('challenge');
    });

    expect(pipeRow())->toBeNull();
    expect($request->session()->get('login.id'))->toBe(99);
});

test('a clean response WITHOUT positive evidence of a verified password clears nothing', function () {
    pipeSeed(2);
    $response = new Response('some other response');

    expect(pipeStage(pipeRequestWithSession(), fn () => $response))->toBe($response);
    expect(pipeRow()->failed_count)->toBe(2);
});

test('a result that is not a response fails closed and touches no state', function () {
    pipeSeed(2);

    expect(fn () => pipeStage(pipeRequestWithSession(['login.id' => 1]), fn () => 'not-a-response'))
        ->toThrow(RuntimeException::class);
    expect(pipeRow()->failed_count)->toBe(2);
});

test('an error that is not a rejected password is inconclusive: not counted, not cleared', function () {
    pipeSeed(2);
    $response = (new Response('server error', 500))->withException(new RuntimeException('session store down'));

    expect(pipeStage(pipeRequest(), fn () => $response))->toBe($response);
    expect(pipeRow()->failed_count)->toBe(2);
});

test('a validation error on another field is inconclusive too', function () {
    pipeSeed(2);
    $response = redirect('/login')->withException(ValidationException::withMessages(['password' => 'x']));

    expect(pipeStage(pipeRequest(), fn () => $response))->toBe($response);
    expect(pipeRow()->failed_count)->toBe(2);
});

test('the stage never stores or logs the submitted password', function () {
    pipeStage(pipeRequest(), fn () => redirect('/login')->withException(ValidationException::withMessages(['email' => 'x'])));

    expect(json_encode(DB::table('login_lockouts')->get()))->not->toContain('secret-should-never-be-stored');
});
