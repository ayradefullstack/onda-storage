<?php

declare(strict_types=1);

use App\Models\Country;
use App\Models\User;
use App\Notifications\Auth\AccountCreatedNotification;
use App\Notifications\Auth\VerifyEmailAddress;
use App\Services\Auth\EmailChangeService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mime\Email;

/**
 * "Your account was created": a NEW registration gets the verification mail as
 * an AccountCreatedNotification (mail + bell row, one notification id); a
 * resend and an email change keep the plain VerifyEmailAddress and write no row.
 *
 * Default test queue is sync + the array mailer; worker semantics use
 * Queue::fake and run the captured job by hand. Fully self-contained.
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->skipUnlessFortifyHas(Features::emailVerification());
    $this->seed(RoleSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function anPayload(array $overrides = []): array
{
    $country = Country::query()->where('alpha2', 'FR')->first() ?? Country::create(['name' => 'France', 'alpha2' => 'FR']);

    return array_merge([
        'first_name' => 'Mohamed',
        'last_name' => 'Benali',
        'first_name_ar' => 'محمد',
        'last_name_ar' => 'بن علي',
        'country_id' => $country->id,
        'city' => 'Lyon',
        'phone' => '0512345678',
        'email' => 'created@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ], $overrides);
}

function anRegister(mixed $test, array $overrides = [], ?string $locale = null): TestResponse
{
    $client = $locale === null ? $test : $test->withUnencryptedCookie('locale', $locale);

    return $client->post(route('register.store'), anPayload($overrides));
}

/** @return Collection<int, DatabaseNotification> */
function anAccountRows(): Collection
{
    return DatabaseNotification::query()->where('type', AccountCreatedNotification::class)->get();
}

/** @return list<Email> */
function anMails(): array
{
    return collect(Mail::mailer('array')->getSymfonyTransport()->messages())
        ->map(fn ($message) => $message->getOriginalMessage())
        ->values()
        ->all();
}

/** @return list<SendQueuedNotifications> */
function anJobs(?string $channel = null): array
{
    return Queue::pushed(
        SendQueuedNotifications::class,
        fn (SendQueuedNotifications $job) => $job->notification instanceof VerifyEmailAddress
            && ($channel === null || $job->channels === [$channel]),
    )->values()->all();
}

function anRunLikeAWorker(SendQueuedNotifications $job): void
{
    unserialize(serialize($job))->handle(app(ChannelManager::class));
}

// ---------------------------------------------------------- registration

test('registration sends one account-created notification: a mail and a bell row sharing one id', function () {
    Queue::fake();

    anRegister($this)->assertSessionHasNoErrors();

    $jobs = anJobs();
    expect($jobs)->toHaveCount(2);
    foreach ($jobs as $job) {
        expect($job->notification)->toBeInstanceOf(AccountCreatedNotification::class);
    }
    expect(collect($jobs)->map(fn ($job) => $job->channels[0])->sort()->values()->all())->toBe(['database', 'mail'])
        ->and(collect($jobs)->map(fn ($job) => $job->notification->id)->unique()->all())->toHaveCount(1);

    foreach ($jobs as $job) {
        anRunLikeAWorker($job);
    }

    $rows = anAccountRows();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($jobs[0]->notification->id)
        ->and(anMails())->toHaveCount(1);
});

test('on the sync queue a registration leaves exactly one row and one mail', function () {
    anRegister($this, locale: 'en')->assertSessionHasNoErrors();

    $user = User::where('email', 'created@example.com')->firstOrFail();
    expect(anAccountRows())->toHaveCount(1)
        ->and(DatabaseNotification::count())->toBe(1)
        ->and(anAccountRows()->first()->notifiable_id)->toBe($user->id);

    $mails = anMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getTo()[0]->getAddress())->toBe('created@example.com')
        ->and($mails[0]->getSubject())->toBe(__('account_created.subject', [], 'en'));

    // The mail is the verification mail too: its signed link activates the account.
    expect(preg_match('~href="([^"]*email/verify/[^"]*)"~', (string) $mails[0]->getHtmlBody(), $match))->toBe(1);
    $this->get(html_entity_decode($match[1]));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('the bell row has the documented shape and no personal data', function () {
    anRegister($this)->assertSessionHasNoErrors();

    $data = anAccountRows()->first()->data;

    expect(array_keys($data))->toEqualCanonicalizing(['type', 'i18n_key', 'params', 'occurred_at', 'url'])
        ->and($data['type'])->toBe('account_created')
        ->and($data['i18n_key'])->toBe('notifications.account.created')
        ->and($data['params'])->toBe([])
        ->and($data['url'])->toBeNull()
        ->and($data['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');

    $json = json_encode($data);
    expect($json)->not->toContain('created@example.com')->not->toContain('Mohamed')->not->toContain('Benali');
});

test('the queued payload carries no address, password, name or secret', function () {
    Queue::fake();
    anRegister($this)->assertSessionHasNoErrors();
    $user = User::where('email', 'created@example.com')->firstOrFail();

    foreach (anJobs() as $job) {
        $payload = serialize($job);

        expect($payload)
            ->toContain(AccountCreatedNotification::class)
            ->not->toContain('created@example.com')
            ->not->toContain((string) $user->getRawOriginal('password'))
            ->not->toContain('Mohamed')
            ->not->toContain('Benali')
            ->not->toContain('two_factor')
            ->not->toContain('remember_token');
    }
});

test('a worker running much later keeps the registration time in the row', function () {
    Queue::fake();
    $registeredAt = CarbonImmutable::parse('2026-03-10 14:30:15', 'UTC');
    $this->travelTo($registeredAt);
    anRegister($this)->assertSessionHasNoErrors();

    $this->travelTo($registeredAt->addHours(3));
    foreach (anJobs() as $job) {
        anRunLikeAWorker($job);
    }

    expect(anAccountRows()->first()->data['occurred_at'])->toBe('2026-03-10T14:30:15Z');
});

test('a worker with another locale still writes the mail in the locale of the registration', function (string $locale) {
    Queue::fake();
    anRegister($this, locale: $locale)->assertSessionHasNoErrors();

    app()->setLocale($locale === 'en' ? 'fr' : 'en');
    foreach (anJobs() as $job) {
        anRunLikeAWorker($job);
    }

    expect(anMails()[0]->getSubject())->toBe(__('account_created.subject', [], $locale));
})->with(['ar', 'fr', 'en']);

test('the mail follows the locale cookie of the request', function (string $locale) {
    anRegister($this, locale: $locale)->assertSessionHasNoErrors();

    $mail = anMails()[0];
    expect($mail->getSubject())->toBe(__('account_created.subject', [], $locale))
        ->and($mail->getSubject())->not->toBe('account_created.subject')
        ->and((string) $mail->getHtmlBody())->toContain(e(__('account_created.title', [], $locale)));

    if ($locale === 'ar') {
        expect((string) $mail->getHtmlBody())->toContain('dir="rtl"');
    }
})->with(['ar', 'fr', 'en']);

// ------------------------------------------------------- retry safety

test('running the bell job twice leaves one row', function () {
    Queue::fake();
    anRegister($this)->assertSessionHasNoErrors();

    $job = anJobs('database')[0];
    anRunLikeAWorker($job);
    anRunLikeAWorker($job);

    expect(anAccountRows())->toHaveCount(1);
});

test('re-running the mail job after the row exists does not add a row, and a verified account gets no mail', function () {
    Queue::fake();
    anRegister($this)->assertSessionHasNoErrors();
    anRunLikeAWorker(anJobs('database')[0]);

    anRunLikeAWorker(anJobs('mail')[0]);
    expect(anAccountRows())->toHaveCount(1)->and(anMails())->toHaveCount(1);

    // Verified meanwhile: a retry of the mail is dropped, the row stays.
    User::where('email', 'created@example.com')->firstOrFail()->markEmailAsVerified();
    anRunLikeAWorker(anJobs('mail')[0]);

    expect(anMails())->toHaveCount(1)->and(anAccountRows())->toHaveCount(1);
});

test('a mail issued for a replaced address is dropped but the row is still written once', function () {
    Queue::fake();
    anRegister($this)->assertSessionHasNoErrors();
    $user = User::where('email', 'created@example.com')->firstOrFail();
    $user->forceFill(['email' => 'moved@example.com'])->save();

    foreach (anJobs() as $job) {
        anRunLikeAWorker($job);
    }

    expect(anMails())->toBe([])->and(anAccountRows())->toHaveCount(1);
});

// ----------------------------------------- not an account-created case

test('resending the verification mail sends the plain notification and writes no row', function () {
    anRegister($this)->assertSessionHasNoErrors();
    $user = User::where('email', 'created@example.com')->firstOrFail();
    expect(anAccountRows())->toHaveCount(1)->and(anMails())->toHaveCount(1);

    Notification::fake();
    $this->actingAs($user->fresh())->post(route('verification.send'))->assertRedirect();

    Notification::assertSentToTimes($user, VerifyEmailAddress::class, 1);
    Notification::assertNotSentTo($user, AccountCreatedNotification::class);
});

test('a resend after registration, un-faked, adds one mail with the verification subject and no row', function () {
    anRegister($this, locale: 'en')->assertSessionHasNoErrors();
    $user = User::where('email', 'created@example.com')->firstOrFail();

    $this->post(route('verification.send'))->assertRedirect();

    $mails = anMails();
    expect($mails)->toHaveCount(2)
        ->and($mails[0]->getSubject())->toBe(__('account_created.subject', [], 'en'))
        ->and($mails[1]->getSubject())->toBe(__('verification.subject', [], 'en'))
        ->and(DatabaseNotification::count())->toBe(1)
        ->and($user->notifications()->count())->toBe(1);
});

test('the account-created flag is single use: the next verification mail on the same instance is the plain one', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $user->markNewlyRegistered();
    $user->sendEmailVerificationNotification();
    $user->sendEmailVerificationNotification();

    Notification::assertSentToTimes($user, AccountCreatedNotification::class, 1);
    Notification::assertSentToTimes($user, VerifyEmailAddress::class, 1);
});

test('a plain verification mail never carries the account-created flag', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $user->sendEmailVerificationNotification();

    Notification::assertNotSentTo($user, AccountCreatedNotification::class);
    expect($user->toArray())->not->toHaveKey('newlyRegistered')
        ->and(serialize($user))->not->toContain('newlyRegistered');
});

test('an email change sends the plain verification mail and writes no row', function () {
    $user = User::factory()->withRole('author')->create(['email' => 'old@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])
        ->assertSessionHasNoErrors();

    $mails = anMails();
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getTo()[0]->getAddress())->toBe('new@example.com')
        ->and(DatabaseNotification::count())->toBe(0);
});

test('an email change through the service queues only VerifyEmailAddress, in the mail channel', function () {
    Queue::fake();
    $user = User::factory()->unverified()->create(['email' => 'a@example.com']);

    app(EmailChangeService::class)->update($user, ['name' => $user->name, 'email' => 'b@example.com']);

    $jobs = anJobs();
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->notification::class)->toBe(VerifyEmailAddress::class)
        ->and($jobs[0]->channels)->toBe(['mail']);
});

// ----------------------------------------------------- failed registration

test('a validation failure sends nothing and writes no row', function () {
    Notification::fake();

    anRegister($this, ['email' => 'not-an-email'])->assertSessionHasErrors('email');

    Notification::assertNothingSent();
    expect(User::count())->toBe(0)->and(DatabaseNotification::count())->toBe(0)->and(anMails())->toBe([]);
});

test('a duplicate email sends nothing to anyone and writes no row', function () {
    $existing = User::factory()->create(['email' => 'created@example.com']);
    Notification::fake();

    anRegister($this)->assertSessionHasErrors('email');

    Notification::assertNothingSent();
    expect(User::count())->toBe(1)->and(DatabaseNotification::count())->toBe(0);
    $this->assertGuest();
    expect($existing->notifications()->count())->toBe(0);
});

test('a registration that cannot complete (author role missing) sends nothing and writes no row', function () {
    Role::query()->where('name', 'author')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    anRegister($this)->assertSessionHasErrors('registration');

    expect(User::withTrashed()->count())->toBe(0)
        ->and(DB::table('notifications')->count())->toBe(0)
        ->and(anMails())->toBe([]);
    $this->assertGuest();
});

test('a registration queued under Queue::fake and then rolled back leaves no job', function () {
    Queue::fake();
    Role::query()->where('name', 'author')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    anRegister($this)->assertSessionHasErrors('registration');

    Queue::assertNothingPushed();
});
