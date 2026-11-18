<?php

declare(strict_types=1);

use App\Actions\Media\TransitionMediaFileStatus;
use App\Domain\Deposit\SubmissionGate;
use App\Domain\Deposit\Value\SubmissionReason;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\RegisterTypeCollege;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use App\Models\User;
use App\Notifications\ReplacementFileUploaded;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Pipeline/helpers.php';

/**
 * The author side of per-file decisions once the whole deposit came back
 * (`rejected`), and the rules that hold in every status:
 *  - an ACCEPTED file is never removable by the author (403, `can_remove`
 *    false), under review, rejected or registered;
 *  - on a rejected deposit a rejected or pending file may be removed, and
 *    an upload into a slot with an unreplaced removed-rejected file is
 *    linked to it (`replaces_media_file_id`);
 *  - nobody is notified of that replacement (only under review);
 *  - accepted decisions survive rejected → submitted → under_review;
 *  - "Soumettre à nouveau" is refused while a rejected file remains.
 */
beforeEach(function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
    Queue::fake();
});

const ROF_REASON = 'Le contrat n’est pas signé par les deux parties, merci de le corriger.';

function rofAdmin(string $name = 'Officer Name'): User
{
    Role::findOrCreate('admin');
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    $user->assignRole('admin');

    return $user;
}

function rofAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['name' => 'Author Person', 'email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function rofCollege(): RegisterTypeCollege
{
    return RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();
}

function rofSlot(string $documentKey = 'justificatif_exploitation'): CollegeOeuvreFile
{
    return rofCollege()->collegeOeuvreFiles()->where('document_key', $documentKey)->firstOrFail();
}

function rofOeuvre(User $author, OeuvreStatus $status, ?User $holder = null): Oeuvre
{
    $college = rofCollege();

    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        'reviewed_by' => $holder?->id,
        'reviewed_at' => $holder === null ? null : now(),
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
    ]);
}

/** A ready file with real ciphertext in a slot, charged like CompleteUpload would. */
function rofFile(Oeuvre $oeuvre, CollegeOeuvreFile $slot, ReviewStatusEnum $review = ReviewStatusEnum::PENDING, string $name = 'piece.pdf'): MediaFile
{
    $author = User::findOrFail($oeuvre->author_id);
    $chunk = config('vault.chunk_size');
    $lastSession = (int) UploadSession::max('id');
    config(['vault.chunk_size' => 8388608]);
    $file = pipelineFile($author, $oeuvre, random_bytes(40), $name, 'application/pdf');
    config(['vault.chunk_size' => $chunk]);
    UploadSession::where('id', '>', $lastSession)->delete();
    $file->college_oeuvre_file_id = $slot->id;
    $file->review_status = $review;
    $file->review_reason = $review === ReviewStatusEnum::REJECTED ? ROF_REASON : null;
    $file->reviewed_at = $review === ReviewStatusEnum::PENDING ? null : now();
    $file->save();

    $quota = StorageQuota::where('user_id', $author->id)->first()
        ?? StorageQuota::factory()->create(['user_id' => $author->id, 'used_bytes' => 0, 'limit_bytes' => 10 * 1024 * 1024 * 1024]);
    $quota->increment('used_bytes', $file->size_bytes);

    test()->travel(1)->seconds();

    return $file;
}

/** One ready, accepted file in every blocking required slot of the collège. */
function rofFillRequired(Oeuvre $oeuvre): array
{
    return $oeuvre->requirements()->required()->whereNull('conditions')->get()
        ->map(fn (CollegeOeuvreFile $slot): MediaFile => rofFile($oeuvre, $slot, ReviewStatusEnum::ACCEPTED, $slot->document_key.'.pdf'))
        ->all();
}

function rofRemove(User $author, MediaFile $file): TestResponse
{
    return test()->actingAs($author)->deleteJson(route('oeuvres.files.destroy', $file));
}

/** The real chunked upload protocol into a slot; returns the new file. */
function rofUpload(User $author, Oeuvre $oeuvre, CollegeOeuvreFile $slot, string $name = 'nouveau.pdf'): MediaFile
{
    $bytes = random_bytes(40);
    $uuid = test()->actingAs($author)->postJson('/uploads', [
        'oeuvre_id' => $oeuvre->id,
        'college_oeuvre_file_id' => $slot->id,
        'filename' => $name,
        'size_bytes' => strlen($bytes),
        'mime' => 'application/pdf',
    ])->assertCreated()->json('uuid');

    foreach (str_split($bytes, 32) as $index => $chunk) {
        test()->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHUNK_CRC32' => hash('crc32b', $chunk),
        ], $chunk)->assertOk();
    }

    $uuid = test()->postJson("/uploads/{$uuid}/complete")->assertCreated()->json('uuid');

    return MediaFile::where('uuid', $uuid)->firstOrFail();
}

// --- accepted files are locked, in every status ---------------------------

test('an accepted file cannot be removed by its author, whatever the oeuvre status', function (OeuvreStatus $status) {
    $author = rofAuthor();
    $oeuvre = rofOeuvre($author, $status, $status === OeuvreStatus::UNDER_REVIEW ? rofAdmin() : null);
    $accepted = rofFile($oeuvre, rofSlot(), ReviewStatusEnum::ACCEPTED, 'accepte.pdf');

    rofRemove($author, $accepted)->assertForbidden();

    expect($accepted->fresh()->trashed())->toBeFalse();

    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('mediaFiles.0.uuid', $accepted->uuid)
            ->where('mediaFiles.0.review_status', 'accepted')
            ->where('mediaFiles.0.can_remove', false));
})->with([
    'under_review' => [OeuvreStatus::UNDER_REVIEW],
    'rejected' => [OeuvreStatus::REJECTED],
    'registered' => [OeuvreStatus::REGISTERED],
]);

test('the inertia (non-JSON) removal of an accepted file on a rejected oeuvre is refused too', function () {
    $author = rofAuthor();
    $oeuvre = rofOeuvre($author, OeuvreStatus::REJECTED);
    $accepted = rofFile($oeuvre, rofSlot(), ReviewStatusEnum::ACCEPTED);

    test()->actingAs($author)->delete(route('oeuvres.files.destroy', $accepted))->assertForbidden();

    expect($accepted->fresh()->trashed())->toBeFalse();
});

// --- a deposit returned to its author -------------------------------------

test('on a rejected oeuvre, a rejected and a pending file are removable, an accepted one is not', function () {
    $author = rofAuthor();
    $oeuvre = rofOeuvre($author, OeuvreStatus::REJECTED);
    $accepted = rofFile($oeuvre, rofSlot('autorisation_compositeur'), ReviewStatusEnum::ACCEPTED, 'a.pdf');
    $rejected = rofFile($oeuvre, rofSlot('file_editeur'), ReviewStatusEnum::REJECTED, 'r.pdf');
    $pending = rofFile($oeuvre, rofSlot(), ReviewStatusEnum::PENDING, 'p.pdf');

    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('mediaFiles.0.can_remove', false)
            ->where('mediaFiles.1.can_remove', true)
            ->where('mediaFiles.1.review_reason', ROF_REASON)
            ->where('mediaFiles.2.can_remove', true));

    rofRemove($author, $accepted)->assertForbidden();
    rofRemove($author, $rejected)->assertOk();
    rofRemove($author, $pending)->assertOk();

    expect($accepted->fresh()->trashed())->toBeFalse()
        ->and($rejected->fresh()->trashed())->toBeTrue()
        ->and($pending->fresh()->trashed())->toBeTrue()
        // Outside review the rejected-file purge does not apply: the
        // retention window holds, the row keeps its decision.
        ->and($rejected->fresh()->purged_at)->toBeNull()
        ->and($rejected->fresh()->review_status)->toBe(ReviewStatusEnum::REJECTED);
});

test('on a rejected oeuvre, an upload into a slot with a removed rejected file is linked to it', function () {
    $author = rofAuthor();
    $oeuvre = rofOeuvre($author, OeuvreStatus::REJECTED);
    $removed = rofFile($oeuvre, rofSlot(), ReviewStatusEnum::REJECTED, 'ancien.pdf');
    rofRemove($author, $removed)->assertOk();

    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('replacement.open', [(string) rofSlot()->id => 1])
            ->where('review_summary.slots.'.rofSlot()->id, 'to_fix'));

    $replacement = rofUpload($author, $oeuvre, rofSlot());

    expect($replacement->replaces_media_file_id)->toBe($removed->id)
        ->and($replacement->review_status)->toBe(ReviewStatusEnum::PENDING)
        ->and($oeuvre->fresh()->status)->toBe(OeuvreStatus::REJECTED);

    // The slot is no longer open; a second upload into it is an ordinary one.
    $second = rofUpload($author, $oeuvre, rofSlot(), 'second.pdf');
    expect($second->replaces_media_file_id)->toBeNull();

    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('replacement.open', [])
            ->where('mediaFiles.0.uuid', $replacement->uuid)
            ->where('mediaFiles.0.replaces.original_name', 'ancien.pdf'));
});

test('on a rejected oeuvre, an upload into a slot with nothing removed links nothing', function () {
    $author = rofAuthor();
    $oeuvre = rofOeuvre($author, OeuvreStatus::REJECTED);
    $removed = rofFile($oeuvre, rofSlot('file_editeur'), ReviewStatusEnum::REJECTED);
    rofRemove($author, $removed)->assertOk();

    expect(rofUpload($author, $oeuvre, rofSlot())->replaces_media_file_id)->toBeNull();
});

// --- notifications --------------------------------------------------------

test('a replacement reaching ready on a rejected oeuvre notifies nobody; under review, once', function () {
    Notification::fake();
    $author = rofAuthor();
    $holder = rofAdmin('Holder');

    $returned = rofOeuvre($author, OeuvreStatus::REJECTED);
    rofRemove($author, rofFile($returned, rofSlot(), ReviewStatusEnum::REJECTED))->assertOk();
    $quiet = rofUpload($author, $returned, rofSlot());
    expect($quiet->replaces_media_file_id)->not->toBeNull();

    app(TransitionMediaFileStatus::class)->handle($quiet->fresh(), MediaFileStatus::READY);

    Notification::assertNothingSent();

    $reviewed = rofOeuvre($author, OeuvreStatus::UNDER_REVIEW, $holder);
    rofRemove($author, rofFile($reviewed, rofSlot(), ReviewStatusEnum::REJECTED))->assertOk();
    $loud = rofUpload($author, $reviewed, rofSlot());

    app(TransitionMediaFileStatus::class)->handle($loud->fresh(), MediaFileStatus::READY);
    app(TransitionMediaFileStatus::class)->handle($loud->fresh(), MediaFileStatus::READY);

    Notification::assertSentToTimes($holder, ReplacementFileUploaded::class, 1);
});

// --- decisions survive the resubmission -----------------------------------

test('accepted decisions survive rejected → submitted → under_review; only the new files are pending', function () {
    $author = rofAuthor();
    $admin = rofAdmin();
    $oeuvre = rofOeuvre($author, OeuvreStatus::UNDER_REVIEW, $admin);
    $files = [
        ...rofFillRequired($oeuvre),
        rofFile($oeuvre, rofSlot('autorisation_compositeur'), ReviewStatusEnum::ACCEPTED, 'compositeur.pdf'),
        rofFile($oeuvre, rofSlot('file_editeur'), ReviewStatusEnum::ACCEPTED, 'editeur.pdf'),
    ];
    $rejected = $files[0];
    $rejected->review_status = ReviewStatusEnum::REJECTED;
    $rejected->review_reason = ROF_REASON;
    $rejected->save();

    test()->actingAs($admin)->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => 'Plusieurs pièces sont à reprendre, voir chaque fichier.'])
        ->assertSessionHasNoErrors();
    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REJECTED);

    rofRemove($author, $rejected)->assertOk();
    $replacement = rofUpload($author, $oeuvre, $rejected->collegeOeuvreFile);
    app(TransitionMediaFileStatus::class)->handle($replacement->fresh(), MediaFileStatus::READY);

    test()->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre))->assertSessionHasNoErrors();
    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::SUBMITTED);

    test()->actingAs($admin)->post(route('admin.oeuvres.review', $oeuvre))->assertSessionHasNoErrors();
    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);

    foreach (array_slice($files, 1) as $file) {
        expect($file->fresh()->review_status)->toBe(ReviewStatusEnum::ACCEPTED);
    }

    expect($replacement->fresh()->review_status)->toBe(ReviewStatusEnum::PENDING)
        ->and($replacement->fresh()->replaces_media_file_id)->toBe($rejected->id)
        ->and($oeuvre->mediaFiles()->where('review_status', ReviewStatusEnum::PENDING)->pluck('id')->all())->toBe([$replacement->id]);
});

// --- "Soumettre à nouveau" -------------------------------------------------

test('resubmission is refused while a rejected file remains, naming each file with its reason', function () {
    $author = rofAuthor();
    $oeuvre = rofOeuvre($author, OeuvreStatus::REJECTED);
    // The blocking required slot, plus an optional one: both rejected.
    $files = [...rofFillRequired($oeuvre), rofFile($oeuvre, rofSlot('file_editeur'), ReviewStatusEnum::ACCEPTED, 'editeur.pdf')];
    foreach ([$files[0], $files[1]] as $file) {
        $file->review_status = ReviewStatusEnum::REJECTED;
        $file->review_reason = ROF_REASON;
        $file->save();
    }

    $verdict = app(SubmissionGate::class)->evaluate($oeuvre);
    $rejected = array_values(array_filter($verdict->blockers, fn (SubmissionReason $r): bool => $r->code === SubmissionReason::FILE_REJECTED));

    expect($verdict->passes())->toBeFalse()
        ->and(array_map(fn (SubmissionReason $r) => $r->params['name'], $rejected))->toBe([$files[0]->original_name, $files[1]->original_name])
        ->and($rejected[0]->params['reason'])->toBe(ROF_REASON)
        ->and($rejected[0]->params['uuid'])->toBe($files[0]->uuid);

    test()->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre))->assertSessionHasErrors('submission');
    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REJECTED);

    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('submission.can_submit', false)
            ->where('submission.blockers.0.code', 'file_rejected')
            ->where('submission.blockers.0.params.reason', ROF_REASON));

    // Removed: the required slot is empty now, still blocking — fill it again.
    foreach ([$files[0], $files[1]] as $file) {
        rofRemove($author, $file)->assertOk();
    }
    $verdict = app(SubmissionGate::class)->evaluate($oeuvre);
    expect($verdict->passes())->toBeFalse()
        ->and(array_map(fn (SubmissionReason $r) => $r->code, $verdict->blockers))->toBe([SubmissionReason::NO_FILES, SubmissionReason::SLOT_EMPTY]);

    $new = rofUpload($author, $oeuvre, $files[0]->collegeOeuvreFile, 'corrige.pdf');
    app(TransitionMediaFileStatus::class)->handle($new->fresh(), MediaFileStatus::READY);

    expect(app(SubmissionGate::class)->evaluate($oeuvre)->passes())->toBeTrue();
    test()->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre))->assertSessionHasNoErrors();
    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::SUBMITTED);
});

test('under review a rejected file is not a gate blocker (the officer decides it there)', function () {
    $author = rofAuthor();
    $oeuvre = rofOeuvre($author, OeuvreStatus::UNDER_REVIEW, rofAdmin());
    $files = rofFillRequired($oeuvre);
    $files[0]->review_status = ReviewStatusEnum::REJECTED;
    $files[0]->save();

    $codes = array_map(fn (SubmissionReason $r) => $r->code, app(SubmissionGate::class)->evaluate($oeuvre)->blockers);

    expect($codes)->not->toContain(SubmissionReason::FILE_REJECTED);
});

// --- the author page -------------------------------------------------------

test('a rejected oeuvre page carries the per-file fields, the counters and no reviewer identity, at a fixed cost', function () {
    $author = rofAuthor();
    $admin = rofAdmin('Amina Officer');
    $oeuvre = rofOeuvre($author, OeuvreStatus::REJECTED);
    rofFile($oeuvre, rofSlot('autorisation_compositeur'), ReviewStatusEnum::ACCEPTED, 'ok.pdf')->forceFill(['reviewed_by' => $admin->id])->save();
    rofFile($oeuvre, rofSlot('file_editeur'), ReviewStatusEnum::REJECTED, 'non.pdf')->forceFill(['reviewed_by' => $admin->id])->save();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $measure = function () use ($author, $oeuvre, &$queries): array {
        $queries = 0;
        $response = test()->actingAs($author)->get(route('oeuvres.show', $oeuvre));

        return [$queries, $response];
    };

    $measure(); // warm-up: permission cache
    [$two, $response] = $measure();

    $response->assertInertia(fn ($page) => $page
        ->where('mediaFiles.0.review_status', 'accepted')
        ->has('mediaFiles.0.reviewed_at')
        ->where('mediaFiles.1.review_status', 'rejected')
        ->where('mediaFiles.1.review_reason', ROF_REASON)
        ->where('mediaFiles.1.can_remove', true)
        ->missing('mediaFiles.1.reviewed_by')
        ->missing('mediaFiles.1.reviewer')
        ->where('review_summary.accepted', 1)
        ->where('review_summary.to_fix', 1)
        ->where('review_summary.waiting', 0)
        ->where('submission.blockers.0.code', 'file_rejected'));

    expect($response->getContent())->not->toContain('Amina');

    foreach (range(1, 4) as $i) {
        rofFile($oeuvre, rofSlot('file_editeur'), ReviewStatusEnum::REJECTED, "non-{$i}.pdf");
    }

    [$six] = $measure();

    expect($six)->toBe($two)
        ->and($two)->toBeLessThanOrEqual(40);
});
