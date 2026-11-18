<?php

declare(strict_types=1);

use App\Actions\Review\DecideMediaFile;
use App\Domain\Access\AccessLogger;
use App\Enums\FileAccessLog\ActionEnum;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\MediaFile;
use App\Models\MediaFileReview;
use App\Models\Oeuvre;
use App\Models\User;
use App\Notifications\MediaFileRejected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

/**
 * Per-file review decisions: DecideMediaFile through its route, the
 * every-file-accepted guard on ApproveOeuvre, the summary, and the author
 * notification. No collège: an unclassified oeuvre has no required slots,
 * so these tests are about file decisions, not the submission gate.
 */
function mfrAdmin(string $name = 'Officer'): User
{
    Role::findOrCreate('admin');
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    $user->assignRole('admin');

    return $user;
}

function mfrAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['name' => 'Author Person', 'email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function mfrOeuvre(?User $holder, OeuvreStatus $status = OeuvreStatus::UNDER_REVIEW, ?User $author = null): Oeuvre
{
    $author ??= mfrAuthor();

    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'submitted_at' => now(),
        'reviewed_by' => $holder?->id,
        'reviewed_at' => $holder === null ? null : now(),
        'registered_at' => $status === OeuvreStatus::REGISTERED ? now() : null,
    ]);
}

/** @param  array<string, mixed>  $attributes */
function mfrFile(Oeuvre $oeuvre, MediaFileStatus $status = MediaFileStatus::READY, array $attributes = []): MediaFile
{
    return MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $oeuvre->author_id,
        'status' => $status,
        ...$attributes,
    ]);
}

function mfrDecide(User $admin, Oeuvre $oeuvre, MediaFile $file, string $decision, ?string $reason = null): TestResponse
{
    return test()->actingAs($admin)->postJson(
        route('admin.oeuvres.files.decide', ['oeuvre' => $oeuvre, 'mediaFile' => $file]),
        array_filter(['decision' => $decision, 'reason' => $reason], fn ($v) => $v !== null),
    );
}

const MFR_REASON = 'Le document est illisible, merci de le déposer à nouveau.';

beforeEach(fn () => Notification::fake());

// --- transitions -------------------------------------------------------------

test('every transition is recorded with its from and to status', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre);

    $steps = [
        ['accepted', null, ReviewStatusEnum::PENDING, ReviewStatusEnum::ACCEPTED],
        ['rejected', MFR_REASON, ReviewStatusEnum::ACCEPTED, ReviewStatusEnum::REJECTED],
        ['accepted', null, ReviewStatusEnum::REJECTED, ReviewStatusEnum::ACCEPTED],
        ['pending', null, ReviewStatusEnum::ACCEPTED, ReviewStatusEnum::PENDING],
        ['rejected', MFR_REASON, ReviewStatusEnum::PENDING, ReviewStatusEnum::REJECTED],
        ['pending', null, ReviewStatusEnum::REJECTED, ReviewStatusEnum::PENDING],
    ];

    foreach ($steps as $i => [$decision, $reason, $from, $to]) {
        mfrDecide($admin, $oeuvre, $file, $decision, $reason)
            ->assertOk()
            ->assertJson(['reviewStatus' => $to->key(), 'replayed' => false]);

        $row = MediaFileReview::query()->orderByDesc('id')->first();

        expect($row->from_status)->toBe($from)
            ->and($row->to_status)->toBe($to)
            ->and($row->reviewer_id)->toBe($admin->id)
            ->and($row->oeuvre_id)->toBe($oeuvre->id)
            ->and($row->reason)->toBe($to === ReviewStatusEnum::REJECTED ? MFR_REASON : null)
            ->and(MediaFileReview::count())->toBe($i + 1);

        $file->refresh();
        expect($file->review_status)->toBe($to)
            ->and($file->reviewed_by)->toBe($admin->id)
            ->and($file->reviewed_at)->not->toBeNull()
            ->and($file->review_reason)->toBe($to === ReviewStatusEnum::REJECTED ? MFR_REASON : null);
    }

    // The pipeline status and the oeuvre status never moved.
    expect($file->status)->toBe(MediaFileStatus::READY)
        ->and($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);
});

test('an identical repeat writes nothing and notifies nobody', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre);

    mfrDecide($admin, $oeuvre, $file, 'pending')->assertOk()->assertJson(['replayed' => true]);
    expect(MediaFileReview::count())->toBe(0);

    mfrDecide($admin, $oeuvre, $file, 'accepted')->assertOk();
    mfrDecide($admin, $oeuvre, $file, 'accepted')->assertOk()->assertJson(['replayed' => true]);
    expect(MediaFileReview::count())->toBe(1);

    mfrDecide($admin, $oeuvre, $file, 'rejected', MFR_REASON)->assertOk();
    mfrDecide($admin, $oeuvre, $file, 'rejected', '  '.MFR_REASON.'  ')->assertOk()->assertJson(['replayed' => true]);
    expect(MediaFileReview::count())->toBe(2);

    Notification::assertSentTimes(MediaFileRejected::class, 1);
});

// --- the reason --------------------------------------------------------------

test('a rejection needs a reason of 20 to 2000 characters, trimmed', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre);

    mfrDecide($admin, $oeuvre, $file, 'rejected')->assertUnprocessable()->assertJsonValidationErrors('reason');
    mfrDecide($admin, $oeuvre, $file, 'rejected', '   ')->assertUnprocessable()->assertJsonValidationErrors('reason');
    mfrDecide($admin, $oeuvre, $file, 'rejected', str_repeat('a', 19))->assertUnprocessable()->assertJsonValidationErrors('reason');
    mfrDecide($admin, $oeuvre, $file, 'rejected', str_repeat('a', 2001))->assertUnprocessable()->assertJsonValidationErrors('reason');

    expect(MediaFileReview::count())->toBe(0);

    mfrDecide($admin, $oeuvre, $file, 'rejected', "   \n".MFR_REASON."  \n")->assertOk();

    expect($file->fresh()->review_reason)->toBe(MFR_REASON)
        ->and(MediaFileReview::sole()->reason)->toBe(MFR_REASON);
});

test('the reason is ignored on accept and reset', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre);

    mfrDecide($admin, $oeuvre, $file, 'accepted', MFR_REASON)->assertOk();

    expect($file->fresh()->review_reason)->toBeNull()
        ->and(MediaFileReview::sole()->reason)->toBeNull();
});

test('an unknown decision key is a 422, never a 500', function (string $key) {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre);

    mfrDecide($admin, $oeuvre, $file, $key)->assertUnprocessable()->assertJsonValidationErrors('decision');
})->with(['approved', '2', 'ACCEPTED', 'accept']);

// --- guards ------------------------------------------------------------------

test('a deposit that is not under review cannot have its files decided', function (OeuvreStatus $status) {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre(null, $status);
    $file = mfrFile($oeuvre);

    mfrDecide($admin, $oeuvre, $file, 'accepted')->assertUnprocessable()->assertJsonValidationErrors('decision');
    expect(MediaFileReview::count())->toBe(0);
})->with([OeuvreStatus::DRAFT, OeuvreStatus::SUBMITTED]);

test('a decided deposit answers 409, naming who decided and when', function () {
    $admin = mfrAdmin('Amina');
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre, attributes: ['review_status' => ReviewStatusEnum::ACCEPTED]);

    test()->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertOk();

    mfrDecide($admin, $oeuvre, $file, 'rejected', MFR_REASON)
        ->assertStatus(409)
        ->assertJsonPath('conflict.kind', 'decided')
        ->assertJsonPath('conflict.by', 'Amina')
        ->assertJsonPath('conflict.status', 'registered');

    expect($file->fresh()->review_status)->toBe(ReviewStatusEnum::ACCEPTED);
});

test('only the officer holding the review may decide; take-over hands it over', function () {
    $holder = mfrAdmin('Amina');
    $other = mfrAdmin('Karim');
    $oeuvre = mfrOeuvre($holder);
    $file = mfrFile($oeuvre);

    mfrDecide($other, $oeuvre, $file, 'accepted')
        ->assertStatus(409)
        ->assertJsonPath('conflict.kind', 'held')
        ->assertJsonPath('conflict.by', 'Amina');

    test()->actingAs($other)->postJson(route('admin.oeuvres.review', $oeuvre), ['take_over' => true])->assertOk();

    mfrDecide($other, $oeuvre, $file, 'accepted')->assertOk();
    mfrDecide($holder, $oeuvre, $file, 'pending')->assertStatus(409)->assertJsonPath('conflict.by', 'Karim');
});

test('a non-admin is refused with 403', function () {
    $holder = mfrAdmin();
    $oeuvre = mfrOeuvre($holder);
    $file = mfrFile($oeuvre);
    $author = User::query()->findOrFail($oeuvre->author_id);

    mfrDecide($author, $oeuvre, $file, 'accepted')->assertForbidden();
    expect($file->fresh()->review_status)->toBe(ReviewStatusEnum::PENDING);
});

test('a file of another oeuvre is a 404', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $foreign = mfrFile(mfrOeuvre($admin));

    mfrDecide($admin, $oeuvre, $foreign, 'accepted')->assertNotFound();
});

test('accept needs a ready file; reject also takes a failed or quarantined one', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);

    foreach ([MediaFileStatus::FAILED, MediaFileStatus::QUARANTINED, MediaFileStatus::PROCESSING] as $status) {
        mfrDecide($admin, $oeuvre, mfrFile($oeuvre, $status), 'accepted')
            ->assertUnprocessable()
            ->assertJsonPath('errors.decision.0', "Only a ready file can be accepted; this file is \"{$status->key()}\".");
    }

    foreach ([MediaFileStatus::FAILED, MediaFileStatus::QUARANTINED] as $status) {
        mfrDecide($admin, $oeuvre, mfrFile($oeuvre, $status), 'rejected', MFR_REASON)->assertOk();
    }

    foreach ([MediaFileStatus::UPLOADING, MediaFileStatus::ASSEMBLING, MediaFileStatus::SCANNING, MediaFileStatus::PROCESSING] as $status) {
        mfrDecide($admin, $oeuvre, mfrFile($oeuvre, $status), 'rejected', MFR_REASON)
            ->assertUnprocessable()
            ->assertJsonPath('errors.decision.0', "This file is still \"{$status->key()}\"; it can be decided once the pipeline has finished with it.");
    }

    expect(MediaFileReview::count())->toBe(2);
});

// --- concurrency: a file rejection racing an oeuvre approval -----------------

test('a file rejection committed before an approve makes the approve refuse', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $a = mfrFile($oeuvre, attributes: ['review_status' => ReviewStatusEnum::ACCEPTED]);
    mfrFile($oeuvre, attributes: ['review_status' => ReviewStatusEnum::ACCEPTED]);

    mfrDecide($admin, $oeuvre, $a, 'rejected', MFR_REASON)->assertOk();

    test()->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertUnprocessable()
        ->assertJsonPath('filesNotAccepted', [['name' => $a->original_name, 'reviewStatus' => 'rejected']]);

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);
});

test('an approve committed before a file rejection makes the rejection a 409', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre, attributes: ['review_status' => ReviewStatusEnum::ACCEPTED]);

    test()->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertOk();
    mfrDecide($admin, $oeuvre, $file, 'rejected', MFR_REASON)->assertStatus(409);

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REGISTERED)
        ->and($file->fresh()->review_status)->toBe(ReviewStatusEnum::ACCEPTED);
});

test('a file decision locks the oeuvre row before the file row, like the oeuvre actions', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    app(DecideMediaFile::class)->handle($file, ReviewStatusEnum::ACCEPTED, $admin, null);

    $selects = array_values(array_filter($queries, fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')));

    // The first two reads of the transaction: the oeuvre, then the file.
    expect($selects[0])->toContain('from "oeuvres"')
        ->and($selects[1])->toContain('from "media_files"');
});

// --- append-only -------------------------------------------------------------

test('media_file_reviews is append-only', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre);

    mfrDecide($admin, $oeuvre, $file, 'accepted')->assertOk();
    $row = MediaFileReview::sole();

    expect(fn () => $row->forceFill(['reason' => 'changed later'])->save())->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class)
        ->and(MediaFileReview::sole()->reason)->toBeNull();
});

// --- was_consulted -----------------------------------------------------------

test('was_consulted is true only after this reviewer consulted this file', function () {
    $admin = mfrAdmin();
    $other = mfrAdmin('Other');
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre);
    $sibling = mfrFile($oeuvre);

    mfrDecide($admin, $oeuvre, $file, 'accepted')->assertOk();
    expect(MediaFileReview::query()->latest('id')->first()->was_consulted)->toBeFalse();

    // Someone else's consultation, or a consultation of another file, does not count.
    AccessLogger::record($file, $other->id, ActionEnum::ADMIN_CONSULT, '127.0.0.1', 'test');
    AccessLogger::record($sibling, $admin->id, ActionEnum::ADMIN_CONSULT, '127.0.0.1', 'test');
    // Neither does an action that is not a consultation.
    AccessLogger::record($file, $admin->id, ActionEnum::INSPECT, '127.0.0.1', 'test');

    mfrDecide($admin, $oeuvre, $file, 'pending')->assertOk();
    expect(MediaFileReview::query()->latest('id')->first()->was_consulted)->toBeFalse();

    AccessLogger::record($file, $admin->id, ActionEnum::CONSULT_ORIGINAL, '127.0.0.1', 'test');

    mfrDecide($admin, $oeuvre, $file, 'accepted')->assertOk();
    expect(MediaFileReview::query()->latest('id')->first()->was_consulted)->toBeTrue();
});

// --- notification ------------------------------------------------------------

test('a rejection queues one database notification to the author, without the reviewer', function () {
    $admin = mfrAdmin('Amina Officer');
    $oeuvre = mfrOeuvre($admin);
    $author = User::query()->findOrFail($oeuvre->author_id);
    $file = mfrFile($oeuvre);

    mfrDecide($admin, $oeuvre, $file, 'accepted')->assertOk();
    Notification::assertNothingSent();

    mfrDecide($admin, $oeuvre, $file, 'rejected', MFR_REASON)->assertOk();

    Notification::assertSentTo($author, MediaFileRejected::class, function (MediaFileRejected $notification, array $channels) use ($author, $file, $oeuvre, $admin): bool {
        $data = $notification->toArray($author);
        $flat = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $channels === ['database']
            && $notification instanceof ShouldQueue
            && $notification->viaQueues() === ['database' => 'default']
            && $notification->afterCommit === true
            && $data['type'] === 'file_rejected'
            && $data['oeuvre_uuid'] === $oeuvre->uuid
            && $data['file_uuid'] === $file->uuid
            && $data['file_name'] === $file->original_name
            && $data['reason'] === MFR_REASON
            && $data['url'] === route('oeuvres.show', $oeuvre->uuid)
            && ! str_contains((string) $flat, 'Amina')
            && ! array_key_exists('reviewer', $data)
            && ! in_array($admin->uuid, $data, true);
    });
    Notification::assertSentTimes(MediaFileRejected::class, 1);
});

test('a changed rejection reason is a new decision and notifies again', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $file = mfrFile($oeuvre);

    mfrDecide($admin, $oeuvre, $file, 'rejected', MFR_REASON)->assertOk();
    mfrDecide($admin, $oeuvre, $file, 'rejected', 'Le document ne correspond pas au type demandé.')->assertOk()->assertJson(['replayed' => false]);

    expect(MediaFileReview::count())->toBe(2)
        ->and(MediaFileReview::query()->latest('id')->first()->from_status)->toBe(ReviewStatusEnum::REJECTED);
    Notification::assertSentTimes(MediaFileRejected::class, 2);
});

test('a refused decision notifies nobody', function () {
    $holder = mfrAdmin();
    $oeuvre = mfrOeuvre($holder);
    $file = mfrFile($oeuvre);

    mfrDecide(mfrAdmin('Other'), $oeuvre, $file, 'rejected', MFR_REASON)->assertStatus(409);

    Notification::assertNothingSent();
});

// --- the oeuvre accept guard and the summary ---------------------------------

test('registration needs every file accepted, and says which are not', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $pending = mfrFile($oeuvre, attributes: ['created_at' => now()->subMinutes(3)]);
    $rejected = mfrFile($oeuvre, attributes: ['created_at' => now()->subMinutes(2), 'review_status' => ReviewStatusEnum::REJECTED, 'review_reason' => MFR_REASON]);
    $accepted = mfrFile($oeuvre, attributes: ['created_at' => now()->subMinute(), 'review_status' => ReviewStatusEnum::ACCEPTED]);

    $expected = [
        ['name' => $pending->original_name, 'reviewStatus' => 'pending'],
        ['name' => $rejected->original_name, 'reviewStatus' => 'rejected'],
    ];

    test()->actingAs($admin)->getJson(route('admin.oeuvres.decision', $oeuvre))
        ->assertOk()
        ->assertJsonPath('checklist.filesNotAccepted', $expected)
        ->assertJsonPath('rejectedFiles', [['name' => $rejected->original_name, 'reason' => MFR_REASON]])
        ->assertJsonPath('blocked', ['approve'])
        ->assertJsonPath('allowed', ['reject']);

    test()->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertUnprocessable()
        ->assertJsonPath('filesNotAccepted', $expected)
        ->assertJsonPath('problems.0.code', 'file_not_accepted');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);

    mfrDecide($admin, $oeuvre, $pending, 'accepted')->assertOk();
    mfrDecide($admin, $oeuvre, $rejected, 'accepted')->assertOk();

    test()->actingAs($admin)->getJson(route('admin.oeuvres.decision', $oeuvre))
        ->assertOk()
        ->assertJsonPath('checklist.filesNotAccepted', [])
        ->assertJsonPath('blocked', [])
        ->assertJsonPath('allowed', ['approve', 'reject']);

    test()->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertOk();
    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REGISTERED)
        ->and($accepted->fresh()->review_status)->toBe(ReviewStatusEnum::ACCEPTED);
});

test('the pages expose allowed per file, computed on the server', function () {
    $holder = mfrAdmin('Amina');
    $other = mfrAdmin('Karim');
    $oeuvre = mfrOeuvre($holder);
    $ready = mfrFile($oeuvre, attributes: ['created_at' => now()->subMinutes(3)]);
    $failed = mfrFile($oeuvre, MediaFileStatus::FAILED, ['created_at' => now()->subMinutes(2)]);
    $scanning = mfrFile($oeuvre, MediaFileStatus::SCANNING, ['created_at' => now()->subMinute()]);
    mfrDecide($holder, $oeuvre, $ready, 'accepted')->assertOk();

    test()->actingAs($holder)->get(route('admin.oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('files.0.review_status', 'accepted')
            ->where('files.0.allowed', ['actions' => ['reject', 'reset'], 'reason' => null])
            ->where('files.1.allowed', ['actions' => ['reject'], 'reason' => null])
            ->where('files.2.allowed', ['actions' => [], 'reason' => 'file_not_ready'])
            ->where('review_counts', ['total' => 3, 'accepted' => 1, 'rejected' => 0, 'pending_replacements' => 0]));

    test()->actingAs($other)->get(route('admin.oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page->where('files.0.allowed', ['actions' => [], 'reason' => 'not_holder']));

    test()->actingAs($holder)->get(route('admin.oeuvres.files.review', ['oeuvre' => $oeuvre, 'mediaFile' => $failed]))
        ->assertInertia(fn ($page) => $page
            ->where('file.review_status', 'pending')
            ->where('file.allowed', ['actions' => ['reject'], 'reason' => null])
            ->where('siblings.0.review_status', 'accepted')
            ->where('siblings.2.uuid', $scanning->uuid));

    $submitted = mfrOeuvre(null, OeuvreStatus::SUBMITTED);
    mfrFile($submitted);
    test()->actingAs($holder)->get(route('admin.oeuvres.show', $submitted))
        ->assertInertia(fn ($page) => $page->where('files.0.allowed', ['actions' => [], 'reason' => 'not_under_review']));

    $registered = mfrOeuvre($holder, OeuvreStatus::REGISTERED);
    mfrFile($registered);
    test()->actingAs($holder)->get(route('admin.oeuvres.show', $registered))
        ->assertInertia(fn ($page) => $page->where('files.0.allowed', ['actions' => [], 'reason' => 'oeuvre_decided']));
});

// --- dedup -------------------------------------------------------------------

test('two rows sharing one hash keep independent review states', function () {
    $admin = mfrAdmin();
    $oeuvre = mfrOeuvre($admin);
    $hash = str_repeat('a', 64);
    $first = mfrFile($oeuvre, attributes: ['sha256_plain' => $hash]);
    $second = mfrFile(mfrOeuvre($admin), attributes: ['sha256_plain' => $hash]);

    mfrDecide($admin, $oeuvre, $first, 'rejected', MFR_REASON)->assertOk();

    expect($first->fresh()->review_status)->toBe(ReviewStatusEnum::REJECTED)
        ->and($second->fresh()->review_status)->toBe(ReviewStatusEnum::PENDING)
        ->and($second->fresh()->review_reason)->toBeNull()
        ->and(MediaFileReview::query()->where('media_file_id', $second->id)->count())->toBe(0);
});

// --- query count -------------------------------------------------------------

test('the oeuvre page and the summary make the same number of queries for 2 or 6 files', function () {
    $admin = mfrAdmin();
    $count = function (string $url) use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->actingAs($admin)->getJson($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $small = mfrOeuvre($admin);
    $large = mfrOeuvre($admin);
    foreach (range(1, 2) as $i) {
        mfrFile($small, attributes: ['review_status' => ReviewStatusEnum::REJECTED, 'review_reason' => MFR_REASON, 'reviewed_by' => $admin->id]);
    }
    foreach (range(1, 6) as $i) {
        mfrFile($large, attributes: ['review_status' => ReviewStatusEnum::REJECTED, 'review_reason' => MFR_REASON, 'reviewed_by' => $admin->id]);
    }

    // Warm up the role cache so both runs start from the same state.
    $count(route('admin.oeuvres.show', $small));

    $showSmall = $count(route('admin.oeuvres.show', $small));
    $showLarge = $count(route('admin.oeuvres.show', $large));
    $summarySmall = $count(route('admin.oeuvres.decision', $small));
    $summaryLarge = $count(route('admin.oeuvres.decision', $large));

    expect($showLarge)->toBe($showSmall)
        ->and($showLarge)->toBeLessThanOrEqual(25)
        ->and($summaryLarge)->toBe($summarySmall)
        ->and($summaryLarge)->toBeLessThanOrEqual(25);
});
