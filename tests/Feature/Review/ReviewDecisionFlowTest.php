<?php

declare(strict_types=1);

use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\RegisterTypeCollege;
use App\Models\User;
use App\Notifications\OeuvreRegisteredNotification;
use App\Notifications\OeuvreRejectedNotification;
use App\Notifications\OeuvreUnderReviewNotification;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

/**
 * The decision flow as the admin pages drive it: JSON in, JSON out, with
 * the three kinds of refusal told apart (see OeuvreReviewController).
 *
 * The machine's own rules — the transition table, the holder guard, the
 * terminality of `registered` — are ReviewDecisionTest's and
 * ReviewConcurrencyTest's. This file is about what was added on top:
 * the registration guards, the reason bounds, replays, "already decided",
 * queued author notifications and what each page is given.
 *
 * MUSIQUE again, for the reason SubmissionGateTest gives: six required
 * documents, exactly one of which (`justificatif_exploitation`) is
 * unconditional.
 */
const FLOW_REASON = 'Le justificatif d\'exploitation est illisible, merci de redéposer un scan net.';

function flowAdmin(string $name = 'Amina'): User
{
    Role::findOrCreate('admin');
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    $user->assignRole('admin');

    return $user;
}

function flowAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

/**
 * An unclassified deposit (no required slots) with one ready file: the
 * registration guards pass unless a test breaks something on purpose.
 */
function flowOeuvre(OeuvreStatus $status = OeuvreStatus::SUBMITTED, ?User $holder = null, ?User $author = null): Oeuvre
{
    $author ??= flowAuthor();

    $oeuvre = Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        'reviewed_by' => $holder?->id,
        'reviewed_at' => $holder === null ? null : now(),
        'registered_at' => $status === OeuvreStatus::REGISTERED ? now() : null,
    ]);

    flowFile($oeuvre);

    return $oeuvre;
}

function flowFile(Oeuvre $oeuvre, MediaFileStatus $status = MediaFileStatus::READY, ?CollegeOeuvreFile $slot = null, string $name = 'partition.pdf'): MediaFile
{
    return MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $oeuvre->author_id,
        'college_oeuvre_file_id' => $slot?->id,
        'original_name' => $name,
        'status' => $status,
        // Reviewed by an officer: registration needs every file accepted.
        'review_status' => $status === MediaFileStatus::READY ? ReviewStatusEnum::ACCEPTED : ReviewStatusEnum::PENDING,
    ]);
}

/** A MUSIQUE deposit under review, held by `$holder`, with no file yet. */
function flowMusique(User $holder): Oeuvre
{
    test()->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $college = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();

    return Oeuvre::factory()->create([
        'author_id' => flowAuthor()->id,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
        'status' => OeuvreStatus::UNDER_REVIEW,
        'submitted_at' => now(),
        'reviewed_by' => $holder->id,
        'reviewed_at' => now(),
    ]);
}

function flowSlot(string $documentKey): CollegeOeuvreFile
{
    return RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail()
        ->collegeOeuvreFiles()->where('document_key', $documentKey)->firstOrFail();
}

function flowRows(Oeuvre $oeuvre): int
{
    return OeuvreReview::where('oeuvre_id', $oeuvre->id)->count();
}

// ---------------------------------------------------------------------
// Transitions
// ---------------------------------------------------------------------

test('the three valid transitions answer with the new status and write one row each', function () {
    $admin = flowAdmin();
    $accepted = flowOeuvre();
    $rejected = flowOeuvre();

    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $accepted))
        ->assertOk()->assertExactJson(['status' => OeuvreStatus::UNDER_REVIEW, 'replayed' => false]);
    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $accepted))
        ->assertOk()->assertExactJson(['status' => OeuvreStatus::REGISTERED, 'replayed' => false]);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $rejected))->assertOk();
    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $rejected), ['reason' => FLOW_REASON])
        ->assertOk()->assertExactJson(['status' => OeuvreStatus::REJECTED, 'replayed' => false]);

    $history = fn (Oeuvre $oeuvre): array => OeuvreReview::where('oeuvre_id', $oeuvre->id)->orderBy('id')
        ->get()->map(fn (OeuvreReview $r): string => "{$r->from_status->key()}->{$r->to_status->key()}")->all();

    expect($history($accepted))->toBe(['submitted->under_review', 'under_review->registered'])
        ->and($history($rejected))->toBe(['submitted->under_review', 'under_review->rejected'])
        ->and($accepted->fresh()->registered_at)->not->toBeNull()
        ->and(OeuvreReview::where('oeuvre_id', $rejected->id)->latest('id')->first())
        ->actor_id->toBe($admin->id)
        ->reason->toBe(FLOW_REASON);
});

test('a submitted deposit cannot be decided without being opened first', function () {
    $admin = flowAdmin();
    $oeuvre = flowOeuvre(OeuvreStatus::SUBMITTED);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $oeuvre), ['reason' => FLOW_REASON])
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::SUBMITTED)
        ->and(flowRows($oeuvre))->toBe(0);
});

test('every move out of registered is a 422, for the officer who registered it too', function () {
    $admin = flowAdmin();
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertOk();

    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $oeuvre), ['reason' => FLOW_REASON, 'take_over' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $oeuvre), ['take_over' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REGISTERED)
        ->and(flowRows($oeuvre))->toBe(1);
});

test('a rejected deposit cannot be registered until the author resubmits', function () {
    $admin = flowAdmin();
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $oeuvre), ['reason' => FLOW_REASON])->assertOk();
    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertUnprocessable()->assertJsonValidationErrors('status');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REJECTED);
});

// ---------------------------------------------------------------------
// The rejection reason
// ---------------------------------------------------------------------

test('a rejection reason must be 20 to 2000 characters once trimmed', function (string $reason, bool $accepted) {
    $admin = flowAdmin();
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $response = $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $oeuvre), ['reason' => $reason]);

    if ($accepted) {
        $response->assertOk();

        expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REJECTED)
            ->and(OeuvreReview::where('oeuvre_id', $oeuvre->id)->first()->reason)->toBe(trim($reason));

        return;
    }

    $response->assertUnprocessable()->assertJsonValidationErrors('reason');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW)
        ->and(flowRows($oeuvre))->toBe(0);
})->with([
    'missing' => ['', false],
    'whitespace only' => ['                         ', false],
    '19 characters' => [str_repeat('a', 19), false],
    '19 characters padded to 30' => ['     '.str_repeat('a', 19).'      ', false],
    '20 characters' => [str_repeat('a', 20), true],
    '20 characters, padded' => ['  '.str_repeat('a', 20).'  ', true],
    '2000 characters' => [str_repeat('a', 2000), true],
    '2001 characters' => [str_repeat('a', 2001), false],
]);

test('a rejection with no reason field at all is refused', function () {
    $admin = flowAdmin();
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $oeuvre))
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
});

// ---------------------------------------------------------------------
// Registration guards
// ---------------------------------------------------------------------

test('registration is refused while an unconditional required document is missing, and says which', function () {
    Notification::fake();

    $admin = flowAdmin();
    $oeuvre = flowMusique($admin);
    // A ready file, but in a conditional slot: the one unconditional
    // document (`justificatif_exploitation`) is still empty.
    flowFile($oeuvre, slot: flowSlot('autorisation_sample'));

    $response = $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertUnprocessable()
        ->assertJsonCount(1, 'problems')
        ->assertJsonPath('problems.0.code', 'slot_empty')
        ->assertJsonPath('problems.0.params.document_key', 'justificatif_exploitation');

    expect($response->json('problems.0.params.name'))->toBe(trim(flowSlot('justificatif_exploitation')->title_global))
        ->and($response->json('errors.approval'))->toHaveCount(1)
        ->and($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW)
        ->and($oeuvre->fresh()->registered_at)->toBeNull()
        ->and(flowRows($oeuvre))->toBe(0);

    Notification::assertNothingSent();
});

test('registration is refused while a file is not ready, and names the file', function (MediaFileStatus $status, string $code) {
    $admin = flowAdmin();
    $oeuvre = flowMusique($admin);
    flowFile($oeuvre, slot: flowSlot('justificatif_exploitation'));
    flowFile($oeuvre, status: $status, name: 'maquette.wav');

    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertUnprocessable()
        ->assertJsonCount(1, 'problems')
        ->assertJsonPath('problems.0.code', $code)
        ->assertJsonPath('problems.0.params.name', 'maquette.wav');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW)
        ->and(flowRows($oeuvre))->toBe(0);
})->with([
    [MediaFileStatus::UPLOADING, 'file_in_pipeline'],
    [MediaFileStatus::ASSEMBLING, 'file_in_pipeline'],
    [MediaFileStatus::SCANNING, 'file_in_pipeline'],
    [MediaFileStatus::PROCESSING, 'file_in_pipeline'],
    [MediaFileStatus::FAILED, 'file_failed'],
    [MediaFileStatus::QUARANTINED, 'file_quarantined'],
]);

test('every problem is listed at once, not only the first', function () {
    $admin = flowAdmin();
    $oeuvre = flowMusique($admin);
    flowFile($oeuvre, status: MediaFileStatus::PROCESSING, name: 'a.pdf');
    flowFile($oeuvre, status: MediaFileStatus::FAILED, name: 'b.pdf');

    $codes = $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertUnprocessable()
        ->json('problems.*.code');

    expect($codes)->toEqualCanonicalizing(['file_in_pipeline', 'file_failed', 'slot_empty']);
});

test('a deposit with no file at all cannot be registered', function () {
    $admin = flowAdmin();
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $admin);
    $oeuvre->mediaFiles()->delete();

    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertUnprocessable()
        ->assertJsonPath('problems.0.code', 'no_files');
});

test('empty conditional documents do not block registration', function () {
    $admin = flowAdmin();
    $oeuvre = flowMusique($admin);
    // Only the unconditional document: the five conditional ones are empty.
    flowFile($oeuvre, slot: flowSlot('justificatif_exploitation'));

    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertOk()->assertJsonPath('status', OeuvreStatus::REGISTERED->key());

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REGISTERED);
});

test('an Inertia form posting an incomplete registration gets the list as validation errors', function () {
    $admin = flowAdmin();
    $oeuvre = flowMusique($admin);
    flowFile($oeuvre, status: MediaFileStatus::SCANNING, name: 'maquette.wav');

    $this->actingAs($admin)->post(route('admin.oeuvres.approve', $oeuvre))->assertInvalid('approval');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);
});

// ---------------------------------------------------------------------
// Replays and conflicts
// ---------------------------------------------------------------------

test('the same officer repeating a registration gets the current state and writes nothing twice', function () {
    Notification::fake();

    $admin = flowAdmin();
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertOk()->assertJsonPath('replayed', false);
    $registeredAt = $oeuvre->fresh()->registered_at;

    $this->travel(5)->minutes();

    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertOk()->assertExactJson(['status' => OeuvreStatus::REGISTERED, 'replayed' => true]);

    expect(flowRows($oeuvre))->toBe(1)
        ->and($oeuvre->fresh()->registered_at->equalTo($registeredAt))->toBeTrue();

    Notification::assertSentToTimes($oeuvre->author, OeuvreRegisteredNotification::class, 1);
});

test('the same officer repeating a rejection or an opening writes nothing twice', function () {
    Notification::fake();

    $admin = flowAdmin();
    $oeuvre = flowOeuvre(OeuvreStatus::SUBMITTED);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $oeuvre))->assertOk();
    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $oeuvre))->assertOk();

    expect(flowRows($oeuvre))->toBe(1);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $oeuvre), ['reason' => FLOW_REASON])->assertOk();
    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $oeuvre), ['reason' => FLOW_REASON])
        ->assertOk()->assertExactJson(['status' => OeuvreStatus::REJECTED, 'replayed' => true]);

    expect(flowRows($oeuvre))->toBe(2);

    Notification::assertSentToTimes($oeuvre->author, OeuvreUnderReviewNotification::class, 1);
    Notification::assertSentToTimes($oeuvre->author, OeuvreRejectedNotification::class, 1);
});

test('a replay through an Inertia form redirects without a second success toast', function () {
    $admin = flowAdmin();
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->post(route('admin.oeuvres.approve', $oeuvre))->assertRedirect();
    $this->actingAs($admin)->post(route('admin.oeuvres.approve', $oeuvre))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(flowRows($oeuvre))->toBe(1);
});

test('two officers registering the same deposit: one row, and the second is told who and when', function () {
    $amina = flowAdmin('Amina');
    $karim = flowAdmin('Karim');
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW);
    $oeuvre->forceFill(['reviewed_by' => null, 'reviewed_at' => null])->save();

    // Both pages were loaded while it was unheld. Amina's request lands
    // first; Karim's hits the machine's lock-and-recheck.
    $this->actingAs($amina)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertOk();

    $response = $this->actingAs($karim)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertConflict()
        ->assertJsonPath('conflict.kind', 'decided')
        ->assertJsonPath('conflict.by', 'Amina')
        ->assertJsonPath('conflict.status', OeuvreStatus::REGISTERED->key());

    $row = OeuvreReview::where('oeuvre_id', $oeuvre->id)->sole();

    expect($row->actor_id)->toBe($amina->id)
        ->and($response->json('conflict.at'))->toBe($row->created_at->toIso8601String());
});

test('an opposite decision after another officer decided is a conflict too, even with take-over', function () {
    $amina = flowAdmin('Amina');
    $karim = flowAdmin('Karim');
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $amina);

    $this->actingAs($amina)->postJson(route('admin.oeuvres.reject', $oeuvre), ['reason' => FLOW_REASON])->assertOk();

    $this->actingAs($karim)->postJson(route('admin.oeuvres.approve', $oeuvre), ['take_over' => true])
        ->assertConflict()
        ->assertJsonPath('conflict.kind', 'decided')
        ->assertJsonPath('conflict.by', 'Amina')
        ->assertJsonPath('conflict.status', OeuvreStatus::REJECTED->key());

    $this->actingAs($karim)->postJson(route('admin.oeuvres.review', $oeuvre))
        ->assertConflict()->assertJsonPath('conflict.kind', 'decided');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REJECTED)
        ->and(flowRows($oeuvre))->toBe(1);
});

test('a deposit another officer holds is a conflict naming the holder, lifted by take-over', function () {
    $amina = flowAdmin('Amina');
    $karim = flowAdmin('Karim');
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $amina);

    foreach (['admin.oeuvres.review', 'admin.oeuvres.approve'] as $route) {
        $this->actingAs($karim)->postJson(route($route, $oeuvre))
            ->assertConflict()
            ->assertJsonPath('conflict.kind', 'held')
            ->assertJsonPath('conflict.by', 'Amina')
            ->assertJsonPath('conflict.status', OeuvreStatus::UNDER_REVIEW->key());
    }

    $this->actingAs($karim)->postJson(route('admin.oeuvres.review', $oeuvre), ['take_over' => true])->assertOk();

    // The take-over itself leaves no history row (the machine records
    // status changes only); the decision that follows names Karim.
    expect($oeuvre->fresh()->reviewed_by)->toBe($karim->id)
        ->and(flowRows($oeuvre))->toBe(0);

    $this->actingAs($karim)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertOk();

    expect(OeuvreReview::where('oeuvre_id', $oeuvre->id)->sole()->actor_id)->toBe($karim->id);
});

test('a conflict is reported before a completeness problem', function () {
    $amina = flowAdmin('Amina');
    $karim = flowAdmin('Karim');
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $amina);
    flowFile($oeuvre, status: MediaFileStatus::FAILED);

    $this->actingAs($karim)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertConflict()->assertJsonPath('conflict.kind', 'held');
});

// ---------------------------------------------------------------------
// Who may decide
// ---------------------------------------------------------------------

test('an author cannot reach any decision route, on their own deposit or another', function (string $route) {
    $author = flowAuthor();
    $own = flowOeuvre(OeuvreStatus::UNDER_REVIEW, author: $author);
    $other = flowOeuvre(OeuvreStatus::UNDER_REVIEW);

    foreach ([$own, $other] as $oeuvre) {
        $this->actingAs($author)->postJson(route($route, $oeuvre), ['reason' => FLOW_REASON])->assertForbidden();

        expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);
    }
})->with(['admin.oeuvres.review', 'admin.oeuvres.approve', 'admin.oeuvres.reject']);

test('a guest is sent to the login page (HTML) or refused (JSON)', function (string $route) {
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW);

    $this->post(route($route, $oeuvre), ['reason' => FLOW_REASON])->assertRedirect(route('login'));
    $this->postJson(route($route, $oeuvre), ['reason' => FLOW_REASON])->assertUnauthorized();

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);
})->with(['admin.oeuvres.review', 'admin.oeuvres.approve', 'admin.oeuvres.reject']);

test('a draft cannot be decided by an admin', function () {
    $admin = flowAdmin();
    $oeuvre = flowOeuvre(OeuvreStatus::DRAFT);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $oeuvre))->assertForbidden();
});

// ---------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------

test('the author is notified once per transition, and nobody else is', function () {
    Notification::fake();

    $admin = flowAdmin();
    $other = flowAdmin('Other');
    $accepted = flowOeuvre();
    $rejected = flowOeuvre();

    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $accepted))->assertOk();
    Notification::assertSentToTimes($accepted->author, OeuvreUnderReviewNotification::class, 1);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $accepted))->assertOk();
    Notification::assertSentToTimes($accepted->author, OeuvreRegisteredNotification::class, 1);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $rejected))->assertOk();
    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $rejected), ['reason' => '  '.FLOW_REASON.'  '])->assertOk();

    Notification::assertSentToTimes($rejected->author, OeuvreRejectedNotification::class, 1);
    Notification::assertSentTo(
        $rejected->author,
        OeuvreRejectedNotification::class,
        fn (OeuvreRejectedNotification $notification): bool => $notification->toArray($rejected->author)['reason'] === FLOW_REASON,
    );

    Notification::assertNothingSentTo($admin);
    Notification::assertNothingSentTo($other);
    Notification::assertCount(4);
});

test('a take-over and a refused decision notify nobody', function () {
    Notification::fake();

    $amina = flowAdmin('Amina');
    $karim = flowAdmin('Karim');
    $oeuvre = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $amina);

    $this->actingAs($karim)->postJson(route('admin.oeuvres.review', $oeuvre), ['take_over' => true])->assertOk();
    $this->actingAs($amina)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertConflict();
    $this->actingAs($karim)->postJson(route('admin.oeuvres.reject', $oeuvre), ['reason' => 'trop court'])->assertUnprocessable();

    Notification::assertNothingSent();
});

test('the author-facing notifications are queued on default, never written inside the request', function (string $route, OeuvreStatus $from, string $class) {
    Queue::fake();

    $admin = flowAdmin();
    $oeuvre = flowOeuvre($from, holder: $from === OeuvreStatus::UNDER_REVIEW ? $admin : null);

    $this->actingAs($admin)->postJson(route($route, $oeuvre), ['reason' => FLOW_REASON])->assertOk();

    expect(new $class($oeuvre, FLOW_REASON))->toBeInstanceOf(ShouldQueue::class)
        // Nothing reached the author's bell during the officer's request.
        ->and($oeuvre->author->notifications()->count())->toBe(0);

    Queue::assertPushedOn(
        'default',
        SendQueuedNotifications::class,
        fn (SendQueuedNotifications $job): bool => $job->notification instanceof $class
            && $job->channels === ['database']
            && $job->notifiables->first()->is($oeuvre->author),
    );
    Queue::assertCount(1);
})->with([
    ['admin.oeuvres.review', OeuvreStatus::SUBMITTED, OeuvreUnderReviewNotification::class],
    ['admin.oeuvres.approve', OeuvreStatus::UNDER_REVIEW, OeuvreRegisteredNotification::class],
    ['admin.oeuvres.reject', OeuvreStatus::UNDER_REVIEW, OeuvreRejectedNotification::class],
]);

test('once the worker runs, the bell row links to the author\'s oeuvre page and names no officer', function () {
    $admin = flowAdmin('Amina Benali');
    $oeuvre = flowOeuvre();

    // QUEUE_CONNECTION=sync under test: the job is serialised and handled.
    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $oeuvre))->assertOk();
    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $oeuvre), ['reason' => FLOW_REASON])->assertOk();

    $rows = $oeuvre->author->notifications()->get();

    expect($rows->pluck('data.i18n_key')->all())
        ->toEqualCanonicalizing(['notifications.oeuvre.under_review', 'notifications.oeuvre.rejected']);

    foreach ($rows as $row) {
        expect($row->data['url'])->toEndWith('/author/oeuvres/'.$oeuvre->uuid)
            ->and($row->data['oeuvre_uuid'])->toBe($oeuvre->uuid)
            ->and(json_encode($row->data))->not->toContain('Amina');
    }

    expect($rows->firstWhere('data.i18n_key', 'notifications.oeuvre.rejected')->data['reason'])->toBe(FLOW_REASON);
});

// ---------------------------------------------------------------------
// What the pages are given
// ---------------------------------------------------------------------

test('the author page carries the reason and the decision date, and no reviewer identity', function () {
    $admin = flowAdmin('Amina Benali');
    $author = flowAuthor();
    $oeuvre = flowOeuvre(author: $author);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $oeuvre))->assertOk();
    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $oeuvre), ['reason' => FLOW_REASON])->assertOk();

    $this->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($admin) {
            $page->component('author/oeuvres/Show')
                ->where('oeuvre.status', OeuvreStatus::REJECTED->key())
                ->has('reviews', 2)
                ->where('reviews.1.to_status', OeuvreStatus::REJECTED->key())
                ->where('reviews.1.reason', FLOW_REASON)
                ->has('reviews.1.created_at')
                ->missing('reviews.0.actor')
                ->missing('reviews.1.actor')
                ->missing('oeuvre.reviewed_by')
                ->missing('holder');

            // Not under another key either: the officer appears nowhere in
            // what this page was sent.
            $props = json_encode($page->toArray()['props']);

            expect($props)->not->toContain('Amina')
                ->not->toContain($admin->email)
                ->not->toContain($admin->uuid);
        });
});

test('the admin page carries the history with its officers, the holder and the checklist', function () {
    $amina = flowAdmin('Amina');
    $karim = flowAdmin('Karim');
    $oeuvre = flowMusique($amina);
    flowFile($oeuvre, status: MediaFileStatus::PROCESSING, name: 'maquette.wav');
    OeuvreReview::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'actor_id' => $amina->id,
        'from_status' => OeuvreStatus::SUBMITTED,
        'to_status' => OeuvreStatus::UNDER_REVIEW,
    ]);

    $this->actingAs($karim)->get(route('admin.oeuvres.show', $oeuvre))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/oeuvres/Show')
            ->where('can_decide', true)
            ->where('holder.name', 'Amina')
            ->where('holder.is_you', false)
            ->where('reviews.0.actor', 'Amina')
            ->where('checks.files', 1)
            ->where('checks.pending', 1)
            ->where('checks.blocked', 0)
            ->where('checks.missing_required', 1)
            ->has('checks.problems', 2)
            ->has('advisories', 5));
});

test('the admin list has an "à examiner" filter and a count over the whole queue', function () {
    $admin = flowAdmin();
    $submitted = flowOeuvre(OeuvreStatus::SUBMITTED);
    $underReview = flowOeuvre(OeuvreStatus::UNDER_REVIEW, holder: $admin);
    flowOeuvre(OeuvreStatus::REGISTERED, holder: $admin);
    flowOeuvre(OeuvreStatus::REJECTED, holder: $admin);
    flowOeuvre(OeuvreStatus::DRAFT);
    flowFile($underReview, status: MediaFileStatus::SCANNING);
    flowFile($underReview, status: MediaFileStatus::QUARANTINED);

    $this->actingAs($admin)->get(route('admin.oeuvres.index', ['status' => 'to_review']))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($submitted, $underReview) {
            $page->component('admin/oeuvres/Index')
                ->where('filters.status', 'to_review')
                ->where('counts.to_review', 2)
                ->has('oeuvres.data', 2);

            $rows = collect($page->toArray()['props']['oeuvres']['data'])->keyBy('uuid');

            expect($rows->keys()->all())->toEqualCanonicalizing([$submitted->uuid, $underReview->uuid])
                ->and($rows[$submitted->uuid]['checks'])->toBe(['files' => 1, 'pending' => 0, 'blocked' => 0, 'missing_required' => 0])
                ->and($rows[$submitted->uuid]['holder'])->toBeNull()
                ->and($rows[$submitted->uuid]['can_decide'])->toBeTrue()
                ->and($rows[$underReview->uuid]['checks'])->toBe(['files' => 3, 'pending' => 1, 'blocked' => 1, 'missing_required' => 0])
                ->and($rows[$underReview->uuid]['holder']['is_you'])->toBeTrue();
        });

    // The count does not follow the filter: it is the queue, always.
    $this->actingAs($admin)->get(route('admin.oeuvres.index', ['status' => OeuvreStatus::REGISTERED->key()]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.to_review', 2)->has('oeuvres.data', 1));
});
