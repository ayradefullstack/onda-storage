<?php

declare(strict_types=1);

use App\Models\Commune;
use App\Models\Country;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use App\Models\Wilaya;
use App\Notifications\Auth\AccountCreatedNotification;
use App\Notifications\Auth\VerifyEmailAddress;
use App\Services\Auth\EmailChangeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

/**
 * Phase 1 of the auth work: email verification is EFFECTIVE (User implements
 * MustVerifyEmail, so the `verified` middleware that already guards every
 * protected route group actually blocks), the mail is queued and sent after
 * commit, and an address change drops the verification.
 *
 * Complements EmailVerificationTest / VerificationNotificationTest (Fortify's
 * stock behaviour), which stay as they were.
 */
beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
    $this->seed(RoleSeeder::class);
});

/** A verification link for the user's CURRENT address, as the mail carries it. */
function verificationLinkFor(User $user, int $minutes = 60): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes($minutes),
        ['id' => $user->getKey(), 'hash' => sha1($user->email)],
    );
}

/** @return array<string, mixed> */
function registrationPayload(string $email = 'new.author@example.com'): array
{
    $country = Country::create(['name' => 'Algeria', 'alpha2' => 'DZ']);
    $wilaya = Wilaya::create([
        'country_id' => $country->id,
        'code' => '16',
        'name_ar' => 'الجزائر',
        'name_fr' => 'Alger',
    ]);
    $commune = Commune::create([
        'wilaya_id' => $wilaya->id,
        'name_ar' => 'الجزائر الوسطى',
        'name_fr' => 'Alger Centre',
    ]);

    return [
        'first_name' => 'Mohamed',
        'last_name' => 'Benali',
        'first_name_ar' => 'محمد',
        'last_name_ar' => 'بن علي',
        'country_id' => $country->id,
        'wilaya_id' => $wilaya->id,
        'commune_id' => $commune->id,
        'phone' => '0512345678',
        'email' => $email,
        'password' => 'password',
        'password_confirmation' => 'password',
    ];
}

/** @return list<SendQueuedNotifications> */
function queuedVerificationJobs(): array
{
    return Queue::pushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof VerifyEmailAddress)
        ->values()
        ->all();
}

// ---------------------------------------------------------------- contract

test('the user model carries the verification contract', function () {
    expect(User::factory()->unverified()->make())
        ->toBeInstanceOf(MustVerifyEmail::class);
});

// ------------------------------------------------------------ registration

test('registration queues exactly one verification notification and sends no mail synchronously', function () {
    Queue::fake();
    Mail::fake();

    $this->post(route('register.store'), registrationPayload())->assertSessionHasNoErrors();

    $user = User::where('email', 'new.author@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();

    // One "account created" notification: a mail job and a bell-row job (one
    // job per channel), both carrying the SAME notification id.
    $jobs = queuedVerificationJobs();
    expect($jobs)->toHaveCount(2);
    foreach ($jobs as $job) {
        expect($job->notification)->toBeInstanceOf(AccountCreatedNotification::class);
        expect($job->notifiables->first()->is($user))->toBeTrue();
    }
    expect(collect($jobs)->map(fn ($job) => $job->channels)->sortBy(fn ($channels) => $channels[0])->values()->all())
        ->toBe([['database'], ['mail']]);
    expect(collect($jobs)->map(fn ($job) => $job->notification->id)->unique()->all())->toHaveCount(1);

    // Nothing reached the mailer inside the request: it is the worker's job.
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('a freshly registered author is held at the verification notice, not given the dashboard', function () {
    Queue::fake();

    $this->post(route('register.store'), registrationPayload())->assertSessionHasNoErrors();

    $this->get(route('author.dashboard'))->assertRedirect(route('verification.notice'));
});

// --------------------------------------------------------- queued delivery

test('the queued job delivers one message to the address on file, with a working signed link', function () {
    // QUEUE_CONNECTION=sync and MAIL_MAILER=array under phpunit.xml: the job
    // runs through the real NotificationSender and the real mail channel.
    $user = User::factory()->unverified()->create(['name' => 'Amina Test']);

    $user->sendEmailVerificationNotification();

    $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);

    $email = $messages[0]->getOriginalMessage();
    expect($email->getTo()[0]->getAddress())->toBe($user->email);
    expect($email->getSubject())->toBe(__('verification.subject'));

    $html = (string) $email->getHtmlBody();
    expect($html)->toContain('Amina Test');
    expect(preg_match('~href="([^"]*email/verify/[^"]*)"~', $html, $match))->toBe(1);

    $link = html_entity_decode($match[1]);
    expect($link)->toContain('signature=')->toContain('expires=')->toContain(sha1($user->email));

    $this->actingAs($user)->get($link);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('the mail body escapes the user name', function () {
    $user = User::factory()->unverified()->create(['name' => '<script>alert(1)</script>']);

    $html = VerifyEmailAddress::for($user, 'fr')->toMail($user)->render();

    expect((string) $html)->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;');
});

test('the queued payload carries no secret attribute of the user', function () {
    Queue::fake();

    $user = User::factory()->unverified()->withTwoFactor()->create();
    $user->sendEmailVerificationNotification();

    // `serialize($job)` is exactly what Queue::createObjectPayload stores.
    $payload = serialize(queuedVerificationJobs()[0]);

    expect($payload)
        ->toContain(VerifyEmailAddress::class)
        // The user is a bare identifier (class + id), not an attribute snapshot.
        ->toContain(ModelIdentifier::class)
        ->not->toContain('password')
        ->not->toContain('two_factor')
        ->not->toContain('remember_token')
        ->not->toContain('first_name')
        ->not->toContain($user->password)
        ->not->toContain((string) $user->getRawOriginal('two_factor_secret'))
        ->not->toContain((string) $user->getRawOriginal('two_factor_recovery_codes'))
        ->not->toContain((string) $user->remember_token)
        // Neither the address nor its (guessable) sha1: only a keyed HMAC.
        ->not->toContain($user->email)
        ->not->toContain(sha1($user->email))
        ->toContain(VerifyEmailAddress::bindingFor($user->email));
});

// Queue::fake() ignores `afterCommit`, so these two run the real (sync) queue
// against the array mailer: a message in the transport means the job ran.
function sentVerificationMessages(): int
{
    return count(Mail::mailer('array')->getSymfonyTransport()->messages());
}

test('the notification is declared after-commit', function () {
    expect(VerifyEmailAddress::for(User::factory()->make())->afterCommit)->toBeTrue();
});

test('the notification is held until the surrounding transaction commits', function () {
    $user = User::factory()->unverified()->create();

    DB::transaction(function () use ($user) {
        $user->sendEmailVerificationNotification();

        expect(sentVerificationMessages())->toBe(0);
    });

    expect(sentVerificationMessages())->toBe(1);
});

test('a rolled-back transaction never sends the notification', function () {
    $user = User::factory()->unverified()->create();

    try {
        DB::transaction(function () use ($user) {
            $user->sendEmailVerificationNotification();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(sentVerificationMessages())->toBe(0);
});

test('a queued mail is dropped when the user verified in the meantime', function () {
    $verified = User::factory()->create();
    $unverified = User::factory()->unverified()->make();

    expect(VerifyEmailAddress::for($verified)->shouldSend($verified, 'mail'))->toBeFalse();
    expect(VerifyEmailAddress::for($unverified)->shouldSend($unverified, 'mail'))->toBeTrue();
});

/**
 * What the worker does: the job is serialised as the queue stores it, read
 * back (the user is re-fetched from the database as it is NOW) and handled
 * by the real channel manager, which sends through the array mailer.
 */
function runQueuedJobLikeAWorker(SendQueuedNotifications $job): void
{
    $restored = unserialize(serialize($job));

    $restored->handle(app(ChannelManager::class));
}

test('a job queued for the previous address is dropped, whichever order the worker takes them', function (bool $staleFirst) {
    Queue::fake();
    $user = User::factory()->unverified()->create(['email' => 'address.a@example.com']);

    $user->sendEmailVerificationNotification(); // issued for A
    app(EmailChangeService::class)->update($user, ['name' => $user->name, 'email' => 'address.b@example.com']); // issued for B

    [$forA, $forB] = queuedVerificationJobs();
    expect(User::find($user->id)->email)->toBe('address.b@example.com');

    foreach ($staleFirst ? [$forA, $forB] : [$forB, $forA] as $job) {
        runQueuedJobLikeAWorker($job);
    }

    // Exactly one mail left the app, and it is the one for B.
    $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);

    $email = $messages[0]->getOriginalMessage();
    expect($email->getTo()[0]->getAddress())->toBe('address.b@example.com');

    $html = (string) $email->getHtmlBody();
    expect($html)->toContain(sha1('address.b@example.com'))->not->toContain(sha1('address.a@example.com'));
    expect(preg_match('~href="([^"]*email/verify/[^"]*)"~', $html, $match))->toBe(1);

    // And that link works for the current address.
    $this->actingAs($user->fresh())->get(html_entity_decode($match[1]));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
})->with(['stale job first' => [true], 'current job first' => [false]]);

test('the address binding ignores case and differs between addresses', function () {
    expect(VerifyEmailAddress::bindingFor('Same@Example.com'))->toBe(VerifyEmailAddress::bindingFor('same@example.com'))
        ->not->toBe(VerifyEmailAddress::bindingFor('other@example.com'));
});

// -------------------------------------------------- protected route groups

dataset('protected requests', [
    'author dashboard' => ['author', 'get', 'author.dashboard'],
    'author oeuvres' => ['author', 'get', 'oeuvres.index'],
    'upload init' => ['author', 'post', 'uploads.init'],
    'admin dashboard' => ['admin', 'get', 'admin.dashboard'],
    'admin oeuvres' => ['admin', 'get', 'admin.oeuvres.index'],
]);

test('an unverified user is bounced to the verification notice', function (string $role, string $method, string $route) {
    $user = User::factory()->unverified()->withRole($role)->create();

    $this->actingAs($user)->{$method}(route($route))->assertRedirect(route('verification.notice'));
})->with('protected requests');

test('an unverified user is refused (403) on JSON endpoints', function () {
    $user = User::factory()->unverified()->withRole('author')->create();

    $this->actingAs($user)->postJson(route('uploads.init'))->assertForbidden();
});

test('an unverified owner cannot reach the media link or stream entry points', function () {
    $author = User::factory()->unverified()->withRole('author')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);
    $file = MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id, 'uploaded_by' => $author->id]);

    $this->actingAs($author)->getJson(route('media.link', $file))->assertForbidden();

    // Even a perfectly signed stream URL is refused: verification comes first.
    $signed = URL::temporarySignedRoute('media.stream', now()->addMinutes(15), ['mediaFile' => $file->uuid, 'u' => $author->id]);
    $this->actingAs($author)->get($signed)->assertRedirect(route('verification.notice'));
});

test('a verified user keeps access to the same entry points', function () {
    $author = User::factory()->withRole('author')->create();
    $admin = User::factory()->withRole('admin')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);
    $file = MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id, 'uploaded_by' => $author->id]);

    $this->actingAs($author)->get(route('author.dashboard'))->assertOk();
    $this->actingAs($author)->get(route('oeuvres.index'))->assertOk();
    $this->actingAs($author)->postJson(route('uploads.init'))->assertStatus(422); // validation, not 403
    $this->actingAs($author)->getJson(route('media.link', $file))->assertOk();
    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    $this->actingAs($admin)->get(route('admin.oeuvres.index'))->assertOk();
});

test('role and ownership rules still apply on top of verification', function () {
    $author = User::factory()->withRole('author')->create();

    $this->actingAs($author)->get(route('admin.dashboard'))->assertForbidden();
});

// ------------------------------------------- reachable while unverified

test('notice, resend, profile and logout stay reachable without a verified email', function () {
    Queue::fake();
    $user = User::factory()->unverified()->withRole('author')->create();

    $this->actingAs($user)->get(route('verification.notice'))->assertOk();
    $this->actingAs($user)->get(route('profile.edit'))->assertOk();

    $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
    expect(queuedVerificationJobs())->toHaveCount(1);

    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
    $this->assertGuest();
});

test('verification resend is throttled to 6 per minute', function () {
    Queue::fake();
    $user = User::factory()->unverified()->create();

    foreach (range(1, 6) as $_) {
        $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
    }

    $this->actingAs($user)->post(route('verification.send'))->assertStatus(429);
    expect(queuedVerificationJobs())->toHaveCount(6);
});

// ------------------------------------------------------------ signed links

test('a valid signed link verifies the address', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get(verificationLinkFor($user));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('a tampered link does not verify', function () {
    $user = User::factory()->unverified()->create();
    $link = verificationLinkFor($user);

    $this->actingAs($user)->get(str_replace('signature=', 'signature=0', $link))->assertForbidden();
    $this->actingAs($user)->get(preg_replace('~/verify/(\d+)/~', '/verify/'.($user->id + 1).'/', $link))->assertForbidden();
    $this->actingAs($user)->get(str_replace(sha1($user->email), sha1('other@example.com'), $link))->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('an expired link does not verify', function () {
    $user = User::factory()->unverified()->create();
    $link = verificationLinkFor($user, minutes: 60);

    $this->travel(61)->minutes();

    $this->actingAs($user)->get($link)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

// ------------------------------------------------------------ email change

test('changing the email address requires verification again and mails the new address', function () {
    Queue::fake();
    $user = User::factory()->withRole('author')->create(['email' => 'old@example.com']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'verification-link-sent');

    $user->refresh();
    expect($user->email)->toBe('new@example.com');
    expect($user->email_verified_at)->toBeNull();

    $jobs = queuedVerificationJobs();
    expect($jobs)->toHaveCount(1);
    expect($jobs[0]->notifiables->first()->is($user))->toBeTrue();

    // Protected access is closed until the new address is confirmed.
    $this->actingAs($user)->get(route('author.dashboard'))->assertRedirect(route('verification.notice'));
});

test('re-submitting the same address, or only changing its case, keeps the verification', function () {
    Queue::fake();
    $user = User::factory()->create(['email' => 'same@example.com']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Renamed', 'email' => 'same@example.com'])
        ->assertSessionHasNoErrors();
    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Renamed', 'email' => 'SAME@Example.com'])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('Renamed');
    expect($user->email)->toBe('same@example.com');
    expect($user->hasVerifiedEmail())->toBeTrue();
    Queue::assertNothingPushed();
});

test('a link issued for the previous address cannot verify the new one', function () {
    Queue::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);
    $oldLink = verificationLinkFor($user);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com']);

    $this->actingAs($user->fresh())->get($oldLink)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    // The link for the new address does work.
    $this->actingAs($user->fresh())->get(verificationLinkFor($user->fresh()));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('a failed validation on the profile form changes nothing and sends nothing', function () {
    Queue::fake();
    $taken = User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'X', 'email' => $taken->email])
        ->assertSessionHasErrors('email');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Queue::assertNothingPushed();
});
