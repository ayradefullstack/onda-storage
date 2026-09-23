<?php

declare(strict_types=1);

use App\Domain\Deposit\OeuvreStatus;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use App\Notifications\OeuvreRegisteredNotification;
use App\Notifications\OeuvreRejectedNotification;
use App\Notifications\OeuvreSubmittedNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

function notifyAdmin(string $name = 'Officer'): User
{
    Role::findOrCreate('admin');
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    $user->assignRole('admin');

    return $user;
}

function notifyAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function notifyOeuvre(User $author, string $status = OeuvreStatus::DRAFT, ?User $holder = null): Oeuvre
{
    $oeuvre = Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        'reviewed_by' => $holder?->id,
        'reviewed_at' => $holder === null ? null : now(),
    ]);

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'status' => 'ready',
    ]);

    return $oeuvre;
}

/**
 * Every i18n key this reader has been sent, as a set.
 *
 * @return list<string>
 */
function notifyKeys(User $user): array
{
    return $user->notifications()->get()
        ->map(fn (DatabaseNotification $n): string => $n->data['i18n_key'])
        ->all();
}

test('a submission notifies every admin and nobody else', function () {
    Notification::fake();

    $author = notifyAuthor();
    $first = notifyAdmin('Amina');
    $second = notifyAdmin('Karim');
    $otherAuthor = notifyAuthor();
    $oeuvre = notifyOeuvre($author);

    $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));

    Notification::assertSentTo([$first, $second], OeuvreSubmittedNotification::class);
    Notification::assertNotSentTo([$author, $otherAuthor], OeuvreSubmittedNotification::class);
});

test('a first submission and a resubmission are visibly different notifications', function () {
    $author = notifyAuthor();
    $admin = notifyAdmin();
    $oeuvre = notifyOeuvre($author);

    $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));

    expect(notifyKeys($admin))->toBe(['notifications.oeuvre.submitted']);

    $this->actingAs($admin)->post(route('admin.oeuvres.review', $oeuvre));
    $this->actingAs($admin)->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => 'Scan illisible.']);
    $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));

    // Asserted as a set rather than "the newest one": both rows land in the
    // same second, and `notifications` has a uuid primary key, so there is
    // no tiebreak that makes "latest" deterministic.
    expect(notifyKeys($admin))->toEqualCanonicalizing([
        'notifications.oeuvre.submitted',
        'notifications.oeuvre.resubmitted',
    ]);
});

test('the submission notification carries label, author, college and file count', function () {
    $author = notifyAuthor();
    $admin = notifyAdmin();
    $oeuvre = notifyOeuvre($author);

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'status' => 'ready',
    ]);

    $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));

    $data = $admin->notifications()->latest()->first()->data;

    expect($data['params']['author'])->toBe($author->name)
        ->and($data['params']['files'])->toBe(2)
        ->and($data['label_source']['uuid'])->toBe($oeuvre->uuid)
        ->and($data['label_source']['title'])->toBe($oeuvre->title)
        ->and($data['oeuvre_uuid'])->toBe($oeuvre->uuid)
        // An officer's row opens the admin console, not the author's page.
        ->and($data['url'])->toContain('/admin/oeuvres/'.$oeuvre->uuid);
});

test('approval notifies only the author', function () {
    Notification::fake();

    $author = notifyAuthor();
    $admin = notifyAdmin();
    $other = notifyAdmin('Other');
    $oeuvre = notifyOeuvre($author, OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->post(route('admin.oeuvres.approve', $oeuvre));

    Notification::assertSentTo($author, OeuvreRegisteredNotification::class);
    Notification::assertNotSentTo([$admin, $other], OeuvreRegisteredNotification::class);
});

test('the registration notification carries the registration date', function () {
    $author = notifyAuthor();
    $admin = notifyAdmin();
    $oeuvre = notifyOeuvre($author, OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->post(route('admin.oeuvres.approve', $oeuvre));

    $data = $author->notifications()->latest()->first()->data;

    expect($data['i18n_key'])->toBe('notifications.oeuvre.registered')
        ->and($data['params']['registered_at'])->not->toBeNull()
        ->and($data['url'])->toContain('/author/oeuvres/'.$oeuvre->uuid);
});

test('rejection notifies only the author, and carries the reason verbatim', function () {
    Notification::fake();

    $author = notifyAuthor();
    $admin = notifyAdmin();
    $other = notifyAdmin('Other');
    $oeuvre = notifyOeuvre($author, OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $reason = 'Le justificatif d\'exploitation est illisible — merci de redéposer un scan net.';

    $this->actingAs($admin)->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => $reason]);

    Notification::assertNotSentTo([$admin, $other], OeuvreRejectedNotification::class);
    Notification::assertSentTo($author, OeuvreRejectedNotification::class,
        fn (OeuvreRejectedNotification $notification) => $notification->toArray($author)['reason'] === $reason
    );
});

test('the stored rejection row holds the reason, so the bell can show it', function () {
    $author = notifyAuthor();
    $admin = notifyAdmin();
    $oeuvre = notifyOeuvre($author, OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $reason = 'Le scan est illisible.';

    $this->actingAs($admin)->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => $reason]);

    $notification = $author->notifications()->latest()->first();

    expect($notification->data['reason'])->toBe($reason)
        ->and($notification->data['i18n_key'])->toBe('notifications.oeuvre.rejected')
        ->and($notification->read_at)->toBeNull();
});

test('the payload stores a key and parameters, never a rendered sentence', function () {
    $author = notifyAuthor();
    $admin = notifyAdmin();
    $oeuvre = notifyOeuvre($author);

    // The actor submits in French; the reader's locale must still decide
    // how the row reads. Nothing locale-dependent may be frozen into the
    // payload's prose — see OeuvreNotification.
    $this->actingAs($author)
        ->withCookie('locale', 'fr')
        ->post(route('author.oeuvres.submit', $oeuvre));

    $data = $admin->notifications()->latest()->first()->data;

    expect($data)->toHaveKeys(['i18n_key', 'params', 'label_source', 'url'])
        ->and($data['i18n_key'])->toStartWith('notifications.oeuvre.');
});

test('the notifications endpoint returns only the reader\'s own rows', function () {
    $author = notifyAuthor();
    $admin = notifyAdmin();
    $oeuvre = notifyOeuvre($author, OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => 'Scan illisible.']);

    $this->actingAs($author)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonCount(1, 'notifications');

    // The officer who made the decision gets no bell for it.
    $this->actingAs($admin)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread_count', 0);
});

test('marking one notification read leaves the others alone', function () {
    $author = notifyAuthor();
    $admin = notifyAdmin();

    $first = notifyOeuvre($author, OeuvreStatus::UNDER_REVIEW, holder: $admin);
    $second = notifyOeuvre($author, OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->post(route('admin.oeuvres.reject', $first), ['reason' => 'Scan illisible.']);
    $this->actingAs($admin)->post(route('admin.oeuvres.approve', $second));

    $one = $author->notifications()->latest()->first();

    $this->actingAs($author)
        ->postJson(route('notifications.read', $one->id))
        ->assertOk()
        ->assertJsonPath('unread_count', 1);

    $this->actingAs($author)
        ->postJson(route('notifications.read-all'))
        ->assertOk()
        ->assertJsonPath('unread_count', 0);
});

test('a reader cannot mark someone else\'s notification read', function () {
    $author = notifyAuthor();
    $stranger = notifyAuthor();
    $admin = notifyAdmin();
    $oeuvre = notifyOeuvre($author, OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->post(route('admin.oeuvres.approve', $oeuvre));

    $target = $author->notifications()->latest()->first();

    $this->actingAs($stranger)->postJson(route('notifications.read', $target->id))->assertOk();

    expect(DatabaseNotification::query()->find($target->id)->read_at)->toBeNull();
});
