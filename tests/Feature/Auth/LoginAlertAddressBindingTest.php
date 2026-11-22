<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Auth\LoginAlertNotification;
use App\Services\Auth\EmailChangeService;
use App\Services\Auth\LoginMethod;
use App\Services\Auth\LoginNotificationService;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The login alert mail goes only to the address that was verified AT LOGIN.
 * Every case runs the REAL serialized queued job the way a worker would.
 */
const LAB_IP = '203.0.113.88';

/** @return list<SendQueuedNotifications> */
function labJobs(string $channel): array
{
    return Queue::pushed(
        SendQueuedNotifications::class,
        fn (SendQueuedNotifications $job) => $job->notification instanceof LoginAlertNotification
            && $job->channels === [$channel],
    )->values()->all();
}

function labLastJob(string $channel): SendQueuedNotifications
{
    $jobs = labJobs($channel);
    expect($jobs)->not->toBeEmpty();

    return end($jobs);
}

function labRun(SendQueuedNotifications $job): void
{
    unserialize(serialize($job))->handle(app(ChannelManager::class));
}

/** @return list<string> recipient addresses of every mail sent so far */
function labRecipients(): array
{
    return collect(Mail::mailer('array')->getSymfonyTransport()->messages())
        ->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress())
        ->values()
        ->all();
}

function labRows(User $user): int
{
    return DatabaseNotification::query()
        ->where('notifiable_id', $user->id)
        ->where('type', LoginAlertNotification::class)
        ->count();
}

function labLogin(User $user): void
{
    RateLimiter::clear(md5('login'.Str::transliterate(Str::lower(trim($user->email)).'|'.LAB_IP)));
    RateLimiter::clear(md5('login'.'ip:'.LAB_IP));
}

function labAccept(User $user): void
{
    app(LoginNotificationService::class)->accepted($user->fresh(), LoginMethod::Password);
}

function labChangeTo(User $user, string $email): void
{
    app(EmailChangeService::class)->update($user->fresh(), ['email' => $email]);
}

beforeEach(fn () => Queue::fake());

test('a real password login by a verified address mails that address', function () {
    $user = User::factory()->withRole('author')->create();
    labLogin($user);

    $this->withServerVariables(['REMOTE_ADDR' => LAB_IP])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    labRun(labLastJob('mail'));
    labRun(labLastJob('database'));

    expect(labRecipients())->toBe([$user->email])
        ->and(labRows($user))->toBe(1);
});

test('unverified at login: no mail, bell row written', function () {
    $user = User::factory()->withRole('author')->unverified()->create();

    labAccept($user);
    labRun(labLastJob('mail'));
    labRun(labLastJob('database'));

    expect(labRecipients())->toBe([])
        ->and(labRows($user))->toBe(1);
});

test('unverified at login then verified before delivery: still no mail, bell row kept', function () {
    $user = User::factory()->withRole('author')->unverified()->create();

    labAccept($user);
    $user->forceFill(['email_verified_at' => now()])->save();

    labRun(labLastJob('mail'));
    labRun(labLastJob('database'));

    expect(labRecipients())->toBe([])
        ->and(labRows($user))->toBe(1);
});

test('verified A changed to B and left unverified: no alert mail', function () {
    $user = User::factory()->withRole('author')->create();

    labAccept($user);
    labChangeTo($user, 'new-owner@example.test');
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    labRun(labLastJob('mail'));
    labRun(labLastJob('database'));

    expect(labRecipients())->toBe([])
        ->and(labRows($user))->toBe(1);
});

test('A changed to B and B verified before the worker: B gets no alert about the old login, a later login mails B', function () {
    $user = User::factory()->withRole('author')->create();

    labAccept($user);
    $oldMailJob = labLastJob('mail');

    labChangeTo($user, 'new-owner@example.test');
    $user->fresh()->forceFill(['email_verified_at' => now()])->save();

    labRun($oldMailJob);
    expect(labRecipients())->toBe([]);

    // A new login with verified B carries B's own binding.
    labAccept($user);
    labRun(labLastJob('mail'));

    expect(labRecipients())->toBe(['new-owner@example.test']);
});

test('a casing-only change keeps the verification and the mail is sent', function () {
    $user = User::factory()->withRole('author')->create();
    $original = $user->email;

    labAccept($user);
    labChangeTo($user, strtoupper($original));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($user->fresh()->email)->toBe($original);

    labRun(labLastJob('mail'));

    expect(labRecipients())->toBe([$original]);
});

test('a retry after the row exists skips the database channel', function () {
    $user = User::factory()->withRole('author')->create();

    labAccept($user);
    $job = labLastJob('database');

    labRun($job);
    labRun($job);

    expect(labRows($user))->toBe(1);
});

test('the serialized payload holds neither the address nor its sha1', function () {
    $user = User::factory()->withRole('author')->create();

    labAccept($user);

    foreach (['mail', 'database'] as $channel) {
        $payload = serialize(labLastJob($channel));
        $email = $user->email;

        foreach ([$email, Str::lower($email), Str::upper($email), sha1($email), sha1(Str::lower($email))] as $needle) {
            expect($payload)->not->toContain($needle);
        }
    }
});
