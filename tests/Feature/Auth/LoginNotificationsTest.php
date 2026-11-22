<?php

declare(strict_types=1);

use App\Exceptions\Auth\LoginLeaseLostException;
use App\Http\Middleware\EnforceLoginLockout;
use App\Models\Country;
use App\Models\User;
use App\Notifications\Auth\LoginAlertNotification;
use App\Services\Auth\LoginLockoutService;
use App\Services\Auth\LoginMethod;
use App\Services\Auth\LoginNotificationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Mime\Email;

/**
 * "You signed in" alerts: one bell row (and, for a verified address, one
 * mail) for every ADMITTED login, and nothing for anything else.
 *
 * The hooks are the three places a login is admitted (EnforceLoginLockout for
 * the password, TwoFactorLoginResponse, PasskeyLoginResponse); the Login
 * event is deliberately not used. Default test queue is sync + the array
 * mailer, so a request leaves its row in `notifications` and its mail in the
 * array transport. Worker semantics (a job run later, outside the request) use
 * Queue::fake and run the captured job by hand.
 */
const LN_IP = '203.0.113.77';

function lnClearThrottles(string $email, string $ip = LN_IP): void
{
    RateLimiter::clear(md5('login'.Str::transliterate(Str::lower(trim($email)).'|'.$ip)));
    RateLimiter::clear(md5('login'.'ip:'.$ip));
}

/**
 * @param  array<string, mixed>  $extra
 */
function lnLogin(mixed $test, string $email, string $password = 'password', array $extra = [], ?string $locale = null): TestResponse
{
    lnClearThrottles($email);

    $client = $test->withServerVariables(['REMOTE_ADDR' => LN_IP]);

    if ($locale !== null) {
        $client = $client->withUnencryptedCookie('locale', $locale);
    }

    return $client->post(route('login.store'), ['email' => $email, 'password' => $password] + $extra);
}

/** @return Collection<int, DatabaseNotification> */
function lnRows(User $user): Collection
{
    return DatabaseNotification::query()
        ->where('notifiable_id', $user->id)
        ->where('type', LoginAlertNotification::class)
        ->get();
}

function lnAllRows(): int
{
    return DatabaseNotification::query()->count();
}

/** @return list<Email> */
function lnMails(): array
{
    return collect(Mail::mailer('array')->getSymfonyTransport()->messages())
        ->map(fn ($message) => $message->getOriginalMessage())
        ->values()
        ->all();
}

/** @return list<SendQueuedNotifications> */
function lnQueuedJobs(?string $channel = null): array
{
    return Queue::pushed(
        SendQueuedNotifications::class,
        fn (SendQueuedNotifications $job) => $job->notification instanceof LoginAlertNotification
            && ($channel === null || $job->channels === [$channel]),
    )->values()->all();
}

/** Run a captured job as a worker would: from its serialised form, outside any request. */
function lnRunLikeAWorker(SendQueuedNotifications $job): void
{
    unserialize(serialize($job))->handle(app(ChannelManager::class));
}

function lnTotpFor(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/** A confirmed-2FA user whose secret we know. */
function lnTwoFactorUser(string &$secret): User
{
    $secret = app(Google2FA::class)->generateSecretKey();

    return User::factory()->withRole('author')->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1', 'recovery-code-2'])),
        'two_factor_confirmed_at' => now(),
    ]);
}

// ------------------------------------------------------------- password

test('a password login leaves one bell row with the password method and mails a verified address once', function () {
    $user = User::factory()->withRole('author')->create();

    lnLogin($this, $user->email, locale: 'en')->assertRedirect(route('author.dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(lnRows($user))->toHaveCount(1);

    $data = lnRows($user)->first()->data;
    expect($data['type'])->toBe('account_login')
        ->and($data['method'])->toBe('password')
        ->and($data['i18n_key'])->toBe('notifications.account.login_password')
        ->and($data['params'])->toBe([])
        ->and($data['url'])->toBe(route('security.edit'))
        ->and($data['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $mails = lnMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getTo()[0]->getAddress())->toBe($user->email)
        ->and($mails[0]->getSubject())->toBe(__('login_alert.subject', [], 'en'));
});

test('an admin account login notifies just the same', function () {
    $admin = User::factory()->withRole('admin')->create();

    lnLogin($this, $admin->email)->assertRedirect(route('admin.dashboard', absolute: false));

    expect(lnRows($admin))->toHaveCount(1)
        ->and(lnRows($admin)->first()->data['method'])->toBe('password')
        ->and(lnMails())->toHaveCount(1);
});

test('only the account owner is told, nobody else', function () {
    $owner = User::factory()->withRole('author')->create();
    $other = User::factory()->withRole('author')->create();

    lnLogin($this, $owner->email);

    expect(lnRows($owner))->toHaveCount(1)
        ->and(lnRows($other))->toHaveCount(0)
        ->and(lnAllRows())->toBe(1)
        ->and(lnMails())->toHaveCount(1)
        ->and(lnMails()[0]->getTo()[0]->getAddress())->toBe($owner->email);
});

test('the notification goes through the real service with the method it is given', function () {
    Notification::fake();
    $user = User::factory()->create();

    app(LoginNotificationService::class)->accepted($user, LoginMethod::Passkey);

    Notification::assertSentToTimes($user, LoginAlertNotification::class, 1);
    Notification::assertSentTo($user, LoginAlertNotification::class, function (LoginAlertNotification $n) use ($user): bool {
        return $n->toArray($user)['method'] === 'passkey' && $n->via($user) === ['mail', 'database'];
    });
});

test('a failure while notifying is reported and never breaks the login', function () {
    $reported = [];
    $this->mock(ExceptionHandler::class, function ($mock) use (&$reported): void {
        $mock->shouldReceive('report')->once()->andReturnUsing(function (Throwable $e) use (&$reported): void {
            $reported[] = $e;
        });
    });
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('notify')->once()->andThrow(new RuntimeException('notifier down'));

    app(LoginNotificationService::class)->accepted($user, LoginMethod::Password);

    expect($reported)->toHaveCount(1)->and($reported[0]->getMessage())->toBe('notifier down');
});

// ------------------------------------------------------ nothing to tell

test('a wrong password sends nothing', function () {
    $user = User::factory()->create();

    lnLogin($this, $user->email, 'wrong-password');

    $this->assertGuest();
    expect(lnAllRows())->toBe(0)->and(lnMails())->toBe([]);
});

test('an unknown account sends nothing', function () {
    lnLogin($this, 'nobody@example.com', 'whatever');

    $this->assertGuest();
    expect(lnAllRows())->toBe(0)->and(lnMails())->toBe([]);
});

test('the password step of a confirmed-2FA user sends nothing while the challenge is pending', function () {
    $secret = '';
    $user = lnTwoFactorUser($secret);

    lnLogin($this, $user->email)->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(lnAllRows())->toBe(0)->and(lnMails())->toBe([]);
});

test('a locked scope answers 429 and sends nothing, even with the right password', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $_) {
        lnLogin($this, $user->email, 'wrong-password');
    }

    $response = lnLogin($this, $user->email);

    $response->assertStatus(429);
    expect($response->headers->get('X-Login-Reason'))->toBe('locked');
    $this->assertGuest();
    expect(lnAllRows())->toBe(0)->and(lnMails())->toBe([]);
});

test('a busy lockout answers 429 and sends nothing', function () {
    $user = User::factory()->create();
    config(['auth-security.lockout.lock_wait_seconds' => 0]);
    $held = Cache::store('database')->lock('login-lockout:'.app(LoginLockoutService::class)->scopeHash($user->email, LN_IP), 30);
    expect($held->get())->toBeTrue();

    $response = lnLogin($this, $user->email);

    $response->assertStatus(429);
    expect($response->headers->get('X-Login-Reason'))->toBe('busy');
    $this->assertGuest();
    expect(lnAllRows())->toBe(0)->and(lnMails())->toBe([]);

    $held->release();
});

test('a lost lease rolls the login back and sends nothing', function () {
    Queue::fake();
    $user = User::factory()->create();
    $request = Request::create('/login', 'POST', ['email' => $user->email, 'password' => 'x'], server: ['REMOTE_ADDR' => LN_IP]);
    $session = app('session.store');
    $session->put('_token', Str::random(40));
    $request->setLaravelSession($session);

    // The password verifies (a login is created), then the lease is found lost.
    $service = new class extends LoginLockoutService
    {
        public function __construct() {}

        public function run(string $email, ?string $ip, Closure $verify): mixed
        {
            $verify();

            throw new LoginLeaseLostException;
        }
    };

    $response = (new EnforceLoginLockout($service, app(LoginNotificationService::class)))
        ->handle($request, function () use ($user) {
            Auth::guard('web')->login($user);

            return redirect('/');
        });

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('X-Login-Reason'))->toBe('busy');
    $this->assertGuest();
    Queue::assertNothingPushed();
    expect(lnAllRows())->toBe(0);
});

test('acting as a user, which is no login at all, sends nothing', function () {
    $user = User::factory()->withRole('author')->create();

    $this->actingAs($user)->get(route('author.dashboard'))->assertOk();

    expect(lnAllRows())->toBe(0)->and(lnMails())->toBe([]);
});

test('restoring a session from the remember cookie sends nothing', function () {
    $user = User::factory()->withRole('author')->create();

    $response = lnLogin($this, $user->email, extra: ['remember' => 'on']);
    $recaller = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === Auth::guard('web')->getRecallerName());
    expect($recaller)->not->toBeNull();
    expect(lnRows($user))->toHaveCount(1);

    // A new browser: no session, only the (encrypted) recaller cookie.
    $this->flushSession();
    Auth::forgetGuards();

    $this->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
        ->get(route('author.dashboard'))
        ->assertOk();

    $this->assertAuthenticatedAs($user);
    expect(lnRows($user))->toHaveCount(1)->and(lnMails())->toHaveCount(1);
});

test('the registration auto-login produces no sign-in alert', function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->seed(RoleSeeder::class);
    $country = Country::create(['name' => 'France', 'alpha2' => 'FR']);

    $this->post(route('register.store'), [
        'first_name' => 'Mohamed',
        'last_name' => 'Benali',
        'first_name_ar' => 'محمد',
        'last_name_ar' => 'بن علي',
        'country_id' => $country->id,
        'city' => 'Lyon',
        'phone' => '0512345678',
        'email' => 'auto@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
    expect(DatabaseNotification::query()->where('type', LoginAlertNotification::class)->count())->toBe(0);
});

// ------------------------------------------------------ second factor

test('a TOTP code completes the login and alerts with the two_factor method', function () {
    $secret = '';
    $user = lnTwoFactorUser($secret);
    lnLogin($this, $user->email)->assertRedirect(route('two-factor.login'));
    expect(lnAllRows())->toBe(0);

    $this->withServerVariables(['REMOTE_ADDR' => LN_IP])
        ->post(route('two-factor.login'), ['code' => lnTotpFor($secret)])
        ->assertRedirect(route('author.dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(lnRows($user))->toHaveCount(1)
        ->and(lnRows($user)->first()->data['method'])->toBe('two_factor')
        ->and(lnRows($user)->first()->data['i18n_key'])->toBe('notifications.account.login_two_factor')
        ->and(lnMails())->toHaveCount(1);
});

test('a recovery code completes the login and alerts with the recovery_code method', function () {
    $secret = '';
    $user = lnTwoFactorUser($secret);
    lnLogin($this, $user->email)->assertRedirect(route('two-factor.login'));

    $this->withServerVariables(['REMOTE_ADDR' => LN_IP])
        ->post(route('two-factor.login'), ['recovery_code' => 'recovery-code-1']);

    $this->assertAuthenticatedAs($user);
    expect(lnRows($user))->toHaveCount(1)
        ->and(lnRows($user)->first()->data['method'])->toBe('recovery_code');
});

test('a wrong TOTP code or recovery code sends nothing', function () {
    $secret = '';
    $user = lnTwoFactorUser($secret);
    lnLogin($this, $user->email)->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login'), ['code' => '000000'])->assertSessionHasErrors();
    $this->post(route('two-factor.login'), ['recovery_code' => 'not-a-code'])->assertSessionHasErrors();

    $this->assertGuest();
    expect(lnAllRows())->toBe(0)->and(lnMails())->toBe([]);
});

// ------------------------------------------------------------ passkey

test('a passkey login alerts with the passkey method', function () {
    $user = User::factory()->withRole('author')->create();
    $passkey = new Passkey(['name' => 'Laptop', 'credential_id' => 'cred-1', 'credential' => []]);
    $passkey->user_id = $user->id;
    $passkey->save();
    $passkey->setRelation('user', $user);

    $this->mock(VerifyPasskey::class, fn ($mock) => $mock->shouldReceive('__invoke')->once()->andReturn($passkey));

    // The challenge options the browser would have been given.
    $this->getJson(route('passkey.login-options'))->assertOk();

    $id = rtrim(strtr(base64_encode('cred-1'), '+/', '-_'), '=');
    $b64 = fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    $clientData = $b64(json_encode(['type' => 'webauthn.get', 'challenge' => $b64('c'), 'origin' => 'https://localhost']));
    $authData = $b64(hash('sha256', 'localhost', true).chr(0x05).pack('N', 1));
    $this->postJson(route('passkey.login'), ['credential' => [
        'id' => $id,
        'rawId' => $id,
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => $clientData,
            'authenticatorData' => $authData,
            'signature' => $b64('sig'),
            'userHandle' => null,
        ],
    ]])->assertOk();

    $this->assertAuthenticatedAs($user);
    expect(lnRows($user))->toHaveCount(1)
        ->and(lnRows($user)->first()->data['method'])->toBe('passkey')
        ->and(lnRows($user)->first()->data['i18n_key'])->toBe('notifications.account.login_passkey');
});

// ------------------------------------------------ unverified recipient

test('an unverified user gets the bell row only, never a mail', function () {
    $user = User::factory()->unverified()->withRole('author')->create();

    lnLogin($this, $user->email);

    $this->assertAuthenticatedAs($user);
    expect(lnRows($user))->toHaveCount(1)
        ->and(lnMails())->toBe([]);
});

// ------------------------------------------------- duplicate processing

test('running the same database job twice still leaves one row', function () {
    Queue::fake();
    $user = User::factory()->withRole('author')->create();
    lnLogin($this, $user->email);

    $jobs = lnQueuedJobs('database');
    expect($jobs)->toHaveCount(1);

    lnRunLikeAWorker($jobs[0]);
    lnRunLikeAWorker($jobs[0]);

    expect(lnRows($user))->toHaveCount(1);
});

test('re-running the mail job after the row exists does not duplicate the row', function () {
    Queue::fake();
    $user = User::factory()->withRole('author')->create();
    lnLogin($this, $user->email);

    lnRunLikeAWorker(lnQueuedJobs('database')[0]);
    expect(lnRows($user))->toHaveCount(1);

    lnRunLikeAWorker(lnQueuedJobs('mail')[0]);
    lnRunLikeAWorker(lnQueuedJobs('mail')[0]);

    expect(lnRows($user))->toHaveCount(1);
});

test('mail and bell row of one login share the notification id, and two logins get distinct ones', function () {
    Queue::fake();
    $user = User::factory()->withRole('author')->create();

    lnLogin($this, $user->email);
    $first = lnQueuedJobs();
    expect($first)->toHaveCount(2)
        ->and($first[0]->notification->id)->toBe($first[1]->notification->id);

    $this->post(route('logout'));
    lnLogin($this, $user->email);
    $all = lnQueuedJobs();
    expect($all)->toHaveCount(4)
        ->and(collect($all)->map(fn ($job) => $job->notification->id)->unique()->all())->toHaveCount(2);

    foreach (lnQueuedJobs('database') as $job) {
        lnRunLikeAWorker($job);
    }

    $rows = lnRows($user);
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('id')->unique()->all())->toHaveCount(2);
});

test('two genuine logins in a row give two rows on the sync queue', function () {
    $user = User::factory()->withRole('author')->create();

    lnLogin($this, $user->email);
    $this->post(route('logout'));
    lnLogin($this, $user->email);

    expect(lnRows($user))->toHaveCount(2)->and(lnMails())->toHaveCount(2);
});

// ------------------------------------------------------- delayed dispatch

test('the alert carries the moment of the login, not the moment a worker runs it', function () {
    Queue::fake();
    $loggedInAt = CarbonImmutable::parse('2026-03-10 14:30:15', 'UTC');
    $this->travelTo($loggedInAt);
    $user = User::factory()->withRole('author')->create(['name' => 'Amina Test']);
    lnLogin($this, $user->email);

    $this->travelTo($loggedInAt->addHours(2));
    foreach (lnQueuedJobs() as $job) {
        lnRunLikeAWorker($job);
    }

    expect(lnRows($user)->first()->data['occurred_at'])->toBe('2026-03-10T14:30:15Z');

    // Displayed in Africa/Algiers (UTC+1, no DST): 15:30 at login, never 17:30.
    $html = (string) lnMails()[0]->getHtmlBody();
    expect($html)->toContain('10/03/2026 15:30')
        ->toContain('UTC+01:00')
        ->not->toContain('17:30');
});

test('a mail is not sent from a job whose address stopped being verified', function () {
    Queue::fake();
    $user = User::factory()->withRole('author')->create();
    lnLogin($this, $user->email);

    $user->forceFill(['email_verified_at' => null])->save();
    foreach (lnQueuedJobs() as $job) {
        lnRunLikeAWorker($job);
    }

    expect(lnMails())->toBe([])->and(lnRows($user))->toHaveCount(1);
});

// ---------------------------------------------------------------- locale

test('the mail is written in the locale of the login request', function (string $locale) {
    $user = User::factory()->withRole('author')->create(['name' => 'Amina Test']);

    lnLogin($this, $user->email, locale: $locale);

    $mails = lnMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getSubject())->toBe(__('login_alert.subject', [], $locale))
        ->and($mails[0]->getSubject())->not->toBe('login_alert.subject');

    $html = (string) $mails[0]->getHtmlBody();
    expect($html)->toContain(e(__('login_alert.title', [], $locale)));

    if ($locale === 'ar') {
        expect($html)->toContain('dir="rtl"');
    }
})->with(['ar', 'fr', 'en']);

test('the locale of the request survives a worker running outside it', function (string $locale) {
    Queue::fake();
    $user = User::factory()->withRole('author')->create();
    lnLogin($this, $user->email, locale: $locale);

    // The worker has its own, different, locale.
    app()->setLocale($locale === 'en' ? 'fr' : 'en');
    foreach (lnQueuedJobs() as $job) {
        lnRunLikeAWorker($job);
    }

    expect(lnMails()[0]->getSubject())->toBe(__('login_alert.subject', [], $locale));
})->with(['ar', 'fr', 'en']);

// --------------------------------------------------------------- privacy

test('the queued payload, the row and the mail carry no secret, address, session id or IP', function () {
    Queue::fake();
    $secret = '';
    $user = lnTwoFactorUser($secret);
    lnLogin($this, $user->email);
    $sessionId = session()->getId();
    $this->post(route('two-factor.login'), ['recovery_code' => 'recovery-code-1']);

    $forbidden = array_filter([
        $user->email,
        $user->password,
        (string) $user->getRawOriginal('password'),
        $secret,
        (string) $user->getRawOriginal('two_factor_secret'),
        (string) $user->getRawOriginal('two_factor_recovery_codes'),
        'recovery-code-1',
        'recovery-code-2',
        $sessionId,
        LN_IP,
    ]);

    $jobs = lnQueuedJobs();
    expect($jobs)->not->toBeEmpty();

    foreach ($jobs as $job) {
        $payload = serialize($job);
        foreach ($forbidden as $needle) {
            expect($payload)->not->toContain($needle);
        }
    }

    foreach ($jobs as $job) {
        lnRunLikeAWorker($job);
    }

    $row = json_encode(lnRows($user)->first()->data);
    foreach ($forbidden as $needle) {
        expect($row)->not->toContain($needle);
    }

    $html = (string) lnMails()[0]->getHtmlBody();
    foreach ([$user->password, $secret, 'recovery-code-1', $sessionId, LN_IP] as $needle) {
        expect($html)->not->toContain($needle);
    }
});

test('the row only has the documented keys', function () {
    $user = User::factory()->withRole('author')->create();
    lnLogin($this, $user->email);

    expect(array_keys(lnRows($user)->first()->data))
        ->toEqualCanonicalizing(['type', 'i18n_key', 'params', 'method', 'occurred_at', 'url']);
});
