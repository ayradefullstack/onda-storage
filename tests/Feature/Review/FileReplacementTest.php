<?php

declare(strict_types=1);

use App\Actions\Media\TransitionMediaFileStatus;
use App\Actions\Oeuvre\RemovalConflict;
use App\Actions\Oeuvre\RemoveMediaFile;
use App\Actions\Review\ApproveOeuvre;
use App\Actions\Upload\InitUpload;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Enums\UploadSession\StatusEnum as UploadSessionStatus;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\MediaFileReview;
use App\Models\Oeuvre;
use App\Models\RegisterTypeCollege;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use App\Models\User;
use App\Notifications\ReplacementFileUploaded;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Pipeline/helpers.php';

/**
 * The author side of per-file review: while a deposit is under review, the
 * author may remove a file the officer REJECTED, and upload one replacement
 * into its slot. Everything else stays frozen.
 *
 * Real bytes go through the real vault (pipelineFile, and the HTTP upload
 * protocol with 32-byte chunks), so the release and quota numbers are the
 * true ones. The post-upload pipeline is faked: these tests are about the
 * review window, not the jobs.
 */
beforeEach(function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
    Queue::fake();
});

const FRP_REASON = 'Le document est illisible, merci de le déposer à nouveau.';

function frpAdmin(string $name = 'Officer'): User
{
    Role::findOrCreate('admin');
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    $user->assignRole('admin');

    return $user;
}

function frpAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['name' => 'Author Person', 'email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function frpSlot(string $documentKey = 'justificatif_exploitation'): CollegeOeuvreFile
{
    return RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail()
        ->collegeOeuvreFiles()->where('document_key', $documentKey)->firstOrFail();
}

function frpOeuvre(User $author, ?User $holder, OeuvreStatus $status = OeuvreStatus::UNDER_REVIEW, bool $classified = true): Oeuvre
{
    $college = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();

    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        'reviewed_by' => $holder?->id,
        'reviewed_at' => $holder === null ? null : now(),
        ...($classified ? [
            'register_type_id' => $college->register_type_id,
            'type_gestion_id' => $college->type_gestion_id,
            'register_type_college_id' => $college->id,
            'code_college_snapshot' => $college->code_college,
        ] : []),
    ]);
}

/**
 * A ready file with real ciphertext in a slot, charged to the author's
 * quota the way CompleteUpload would have charged it.
 */
function frpFile(Oeuvre $oeuvre, ?CollegeOeuvreFile $slot, ReviewStatusEnum $review = ReviewStatusEnum::PENDING, string $name = 'justificatif.pdf', ?string $bytes = null): MediaFile
{
    $author = User::findOrFail($oeuvre->author_id);
    // pipelineFile writes one chunk: the 32-byte upload chunks of these
    // tests only apply to the HTTP uploads.
    $chunk = config('vault.chunk_size');
    $lastSession = (int) UploadSession::max('id');
    config(['vault.chunk_size' => 8388608]);
    $file = pipelineFile($author, $oeuvre, $bytes ?? random_bytes(40), $name, 'application/pdf');
    config(['vault.chunk_size' => $chunk]);
    // CompleteUpload deletes its session; pipelineFile does not. Left
    // behind, it would count as a live reservation.
    UploadSession::where('id', '>', $lastSession)->delete();
    $file->college_oeuvre_file_id = $slot?->id;
    $file->review_status = $review;
    $file->review_reason = $review === ReviewStatusEnum::REJECTED ? FRP_REASON : null;
    $file->reviewed_at = $review === ReviewStatusEnum::PENDING ? null : now();
    $file->save();

    $quota = StorageQuota::where('user_id', $author->id)->first()
        ?? StorageQuota::factory()->create(['user_id' => $author->id, 'used_bytes' => 0, 'limit_bytes' => 10 * 1024 * 1024 * 1024]);
    $quota->increment('used_bytes', $file->size_bytes);

    // Distinct timestamps keep the pages' created_at order deterministic.
    test()->travel(1)->seconds();

    return $file;
}

function frpRemove(User $author, MediaFile $file): TestResponse
{
    return test()->actingAs($author)->deleteJson(route('oeuvres.files.destroy', $file));
}

function frpInit(User $author, Oeuvre $oeuvre, ?CollegeOeuvreFile $slot, int $size = 40, string $name = 'nouveau.pdf'): TestResponse
{
    return test()->actingAs($author)->postJson('/uploads', array_filter([
        'oeuvre_id' => $oeuvre->id,
        'college_oeuvre_file_id' => $slot?->id,
        'filename' => $name,
        'size_bytes' => $size,
        'mime' => 'application/pdf',
    ], fn ($value) => $value !== null));
}

function frpChunk(string $uuid, int $index, string $bytes): TestResponse
{
    return test()->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CHUNK_CRC32' => hash('crc32b', $bytes),
    ], $bytes);
}

/** Init → every chunk; returns the session uuid, not completed. */
function frpSend(User $author, Oeuvre $oeuvre, ?CollegeOeuvreFile $slot, string $bytes): string
{
    $uuid = frpInit($author, $oeuvre, $slot, strlen($bytes))->assertCreated()->json('uuid');

    foreach (str_split($bytes, 32) as $index => $chunk) {
        frpChunk($uuid, $index, $chunk)->assertOk();
    }

    return $uuid;
}

function frpReplace(User $author, Oeuvre $oeuvre, CollegeOeuvreFile $slot, string $bytes = ''): MediaFile
{
    $uuid = frpSend($author, $oeuvre, $slot, $bytes === '' ? random_bytes(40) : $bytes);
    $uuid = test()->postJson("/uploads/{$uuid}/complete")->assertCreated()->json('uuid');

    return MediaFile::where('uuid', $uuid)->firstOrFail();
}

function frpUsed(User $author): int
{
    return (int) StorageQuota::where('user_id', $author->id)->value('used_bytes');
}

// --- removing a rejected file -------------------------------------------------

test('the author removes a rejected file under review: purged at once, bytes released, quota credited', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $keep = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::ACCEPTED, 'garde.pdf', random_bytes(50));
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'rejete.pdf', random_bytes(70));
    $vault = Storage::disk('vault');

    expect(frpUsed($author))->toBe(120)
        ->and($vault->exists($file->path))->toBeTrue();

    frpRemove($author, $file)->assertOk()->assertJson(['uuid' => $file->uuid, 'removed' => true]);

    $row = MediaFile::withTrashed()->findOrFail($file->id);

    expect($row->trashed())->toBeTrue()
        ->and($row->purged_at)->not->toBeNull()
        // The row, its hash and its review state stay: legal evidence.
        ->and($row->sha256_plain)->toBe($file->sha256_plain)
        ->and($row->review_status)->toBe(ReviewStatusEnum::REJECTED)
        ->and($vault->exists($file->path))->toBeFalse()
        ->and($vault->exists($file->mac_path))->toBeFalse()
        ->and(frpUsed($author))->toBe(50)
        ->and($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW)
        ->and($keep->fresh()->trashed())->toBeFalse();
});

test('a repeated removal changes nothing and credits the quota once', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    frpFile($oeuvre, frpSlot(), ReviewStatusEnum::ACCEPTED, 'garde.pdf', random_bytes(50));
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'rejete.pdf', random_bytes(70));

    frpRemove($author, $file)->assertOk();
    $purgedAt = MediaFile::withTrashed()->findOrFail($file->id)->purged_at;

    frpRemove($author, $file)->assertOk()->assertJson(['removed' => true]);

    expect(frpUsed($author))->toBe(50)
        ->and(MediaFile::withTrashed()->findOrFail($file->id)->purged_at?->toIso8601String())->toBe($purgedAt?->toIso8601String());
});

test('pending and accepted files cannot be removed under review', function (ReviewStatusEnum $review) {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $file = frpFile($oeuvre, frpSlot(), $review);

    frpRemove($author, $file)->assertForbidden();

    expect($file->fresh()->trashed())->toBeFalse()
        ->and(Storage::disk('vault')->exists($file->path))->toBeTrue();
})->with([
    'pending' => [ReviewStatusEnum::PENDING],
    'accepted' => [ReviewStatusEnum::ACCEPTED],
]);

test('another author cannot remove a rejected file', function () {
    $oeuvre = frpOeuvre(frpAuthor(), frpAdmin());
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);

    frpRemove(frpAuthor(), $file)->assertForbidden();

    expect($file->fresh()->trashed())->toBeFalse();
});

test('a rejected file cannot be removed from a submitted deposit', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, null, OeuvreStatus::SUBMITTED);
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);

    frpRemove($author, $file)->assertForbidden();

    expect($file->fresh()->trashed())->toBeFalse();
});

test('a rejected file of a deposit registered since the page loaded answers 409 oeuvre_decided', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, null, OeuvreStatus::REGISTERED);
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);

    frpRemove($author, $file)->assertStatus(409)->assertJson(['error' => RemovalConflict::OEUVRE_DECIDED]);

    expect($file->fresh()->trashed())->toBeFalse();
});

test('another author gets 403, never the 409 that would reveal the file state', function () {
    $oeuvre = frpOeuvre(frpAuthor(), null, OeuvreStatus::REGISTERED);
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);

    frpRemove(frpAuthor(), $file)->assertForbidden();
});

test('a file the officer has just accepted again answers 409 file_no_longer_rejected', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    MediaFileReview::forceCreate([
        'media_file_id' => $file->id, 'oeuvre_id' => $oeuvre->id, 'reviewer_id' => $admin->id,
        'from_status' => ReviewStatusEnum::PENDING, 'to_status' => ReviewStatusEnum::REJECTED,
        'reason' => FRP_REASON, 'was_consulted' => false, 'decided_at' => now(),
    ]);

    test()->actingAs($admin)->postJson(route('admin.oeuvres.files.decide', ['oeuvre' => $oeuvre, 'mediaFile' => $file]), ['decision' => 'accepted'])->assertOk();

    frpRemove($author, $file)->assertStatus(409)->assertJson(['error' => RemovalConflict::NO_LONGER_REJECTED]);

    expect($file->fresh()->trashed())->toBeFalse();
});

test('inside the lock: a deposit decided after the page loaded answers oeuvre_decided', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    $file->load('oeuvre');

    // The officer registers the deposit between the author's page load and
    // the removal: the loaded model still says under_review.
    Oeuvre::whereKey($oeuvre->id)->update(['status' => OeuvreStatus::REGISTERED]);

    expect(fn () => app(RemoveMediaFile::class)->handle($file))
        ->toThrow(fn (RemovalConflict $e) => expect($e->kind)->toBe(RemovalConflict::OEUVRE_DECIDED));

    expect($file->fresh()->trashed())->toBeFalse();
});

test('DecideMediaFile refuses a file the author removed', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    frpRemove($author, $file)->assertOk();

    test()->actingAs($admin)->postJson(route('admin.oeuvres.files.decide', ['oeuvre' => $oeuvre, 'mediaFile' => $file]), ['decision' => 'accepted'])
        ->assertUnprocessable();

    expect(MediaFile::withTrashed()->findOrFail($file->id)->review_status)->toBe(ReviewStatusEnum::REJECTED);
});

test('dedup: removing a rejected file whose bytes another row shares keeps the shared bytes', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    $twin = MediaFile::factory()->create([
        'oeuvre_id' => frpOeuvre($author, null, OeuvreStatus::DRAFT)->id,
        'uploaded_by' => $author->id,
        'disk' => $file->disk, 'path' => $file->path, 'mac_path' => $file->mac_path,
        'sha256_plain' => $file->sha256_plain, 'status' => MediaFileStatus::READY,
    ]);

    frpRemove($author, $file)->assertOk();

    expect(Storage::disk('vault')->exists($file->path))->toBeTrue()
        ->and(Storage::disk('vault')->exists($file->mac_path))->toBeTrue()
        ->and($twin->fresh()->trashed())->toBeFalse()
        // Review state is per row: the twin keeps its own.
        ->and($twin->fresh()->review_status)->toBe(ReviewStatusEnum::PENDING);
});

test('the restrict foreign keys hold: a purged file keeps its row and its review history', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);
    $file = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    MediaFileReview::forceCreate([
        'media_file_id' => $file->id, 'oeuvre_id' => $oeuvre->id, 'reviewer_id' => $admin->id,
        'from_status' => ReviewStatusEnum::PENDING, 'to_status' => ReviewStatusEnum::REJECTED,
        'reason' => FRP_REASON, 'was_consulted' => false, 'decided_at' => now(),
    ]);
    frpRemove($author, $file)->assertOk();
    $replacement = frpReplace($author, $oeuvre, frpSlot());

    expect(MediaFileReview::where('media_file_id', $file->id)->count())->toBe(1)
        ->and($replacement->replaces_media_file_id)->toBe($file->id)
        // A hard delete of the replaced row must fail loudly, not orphan.
        ->and(fn () => DB::table('media_files')->where('id', $file->id)->delete())->toThrow(QueryException::class);
});

// --- initiating a replacement -------------------------------------------------

test('a replacement init reserves the oldest removed rejected file of its slot, chosen by the server', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $first = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'a.pdf');
    $second = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'b.pdf');
    $this->travel(-2)->minutes();
    frpRemove($author, $first)->assertOk();
    $this->travelBack();
    frpRemove($author, $second)->assertOk();

    $uuid = frpInit($author, $oeuvre, frpSlot(), 40)
        ->assertCreated()
        ->assertJsonMissingPath('replaces_media_file_id')
        ->json('uuid');

    expect(UploadSession::where('uuid', $uuid)->value('replaces_media_file_id'))->toBe($first->id);
});

test('the client cannot choose which file it replaces', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $first = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'a.pdf');
    $second = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'b.pdf');
    frpRemove($author, $first)->assertOk();
    $this->travel(1)->minutes();
    frpRemove($author, $second)->assertOk();

    $uuid = test()->actingAs($author)->postJson('/uploads', [
        'oeuvre_id' => $oeuvre->id, 'college_oeuvre_file_id' => frpSlot()->id,
        'filename' => 'n.pdf', 'size_bytes' => 40, 'mime' => 'application/pdf',
        'replaces_media_file_id' => $second->id,
    ])->assertCreated()->json('uuid');

    expect(UploadSession::where('uuid', $uuid)->value('replaces_media_file_id'))->toBe($first->id);
});

test('a slot with no removed rejected file takes no upload under review', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);

    // Rejected but not removed: nothing is open anywhere.
    frpInit($author, $oeuvre, frpSlot())->assertUnprocessable()->assertJsonValidationErrors('oeuvre_id');

    // Open in another slot only: this slot is still closed.
    $other = frpFile($oeuvre, frpSlot('file_editeur'), ReviewStatusEnum::REJECTED, 'editeur.pdf');
    frpRemove($author, $other)->assertOk();
    frpInit($author, $oeuvre, frpSlot())->assertUnprocessable()->assertJsonValidationErrors('college_oeuvre_file_id');

    expect(UploadSession::count())->toBe(0);
});

test('a slot that does not exist for the oeuvre, or an unclassified oeuvre, takes no replacement', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    frpRemove($author, frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED))->assertOk();

    $foreign = RegisterTypeCollege::where('code_college', '!=', 'MUSIQUE')->firstOrFail()->collegeOeuvreFiles()->firstOrFail();
    frpInit($author, $oeuvre, $foreign)->assertUnprocessable()->assertJsonValidationErrors('college_oeuvre_file_id');

    $legacy = frpOeuvre($author, frpAdmin(), classified: false);
    $removed = frpFile($legacy, null, ReviewStatusEnum::REJECTED);
    frpRemove($author, $removed)->assertOk();
    frpInit($author, $legacy, null)->assertUnprocessable()->assertJsonValidationErrors('oeuvre_id');

    expect(UploadSession::count())->toBe(0);
});

test('a deposit not under review takes no replacement', function (OeuvreStatus $status) {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, null, $status);
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    $removed->delete();

    frpInit($author, $oeuvre, frpSlot())->assertUnprocessable()->assertJsonValidationErrors('oeuvre_id');
})->with([
    'submitted' => [OeuvreStatus::SUBMITTED],
    'registered' => [OeuvreStatus::REGISTERED],
]);

test('another author cannot initiate a replacement', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    frpRemove($author, frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED))->assertOk();

    frpInit(frpAuthor(), $oeuvre, frpSlot())->assertForbidden();
});

test('two inits for one open slot: one reserves the file, the other is refused', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    frpRemove($author, $removed)->assertOk();

    frpInit($author, $oeuvre, frpSlot())->assertCreated();
    frpInit($author, $oeuvre, frpSlot())->assertUnprocessable();

    expect(UploadSession::whereNotNull('replaces_media_file_id')->pluck('replaces_media_file_id')->all())->toBe([$removed->id]);
});

test('two open files in one slot: two inits reserve two different files', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $a = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'a.pdf');
    $b = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'b.pdf');
    frpRemove($author, $a)->assertOk();
    frpRemove($author, $b)->assertOk();

    frpInit($author, $oeuvre, frpSlot())->assertCreated();
    frpInit($author, $oeuvre, frpSlot())->assertCreated();
    frpInit($author, $oeuvre, frpSlot())->assertUnprocessable();

    expect(UploadSession::orderBy('id')->pluck('replaces_media_file_id')->sort()->values()->all())->toBe([$a->id, $b->id]);
});

test('an aborted or an expired session frees its reservation', function (string $how) {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    frpRemove($author, $removed)->assertOk();

    $uuid = frpInit($author, $oeuvre, frpSlot())->assertCreated()->json('uuid');
    frpInit($author, $oeuvre, frpSlot())->assertUnprocessable();

    if ($how === 'aborted') {
        test()->actingAs($author)->deleteJson("/uploads/{$uuid}")->assertSuccessful();
    } else {
        UploadSession::where('uuid', $uuid)->update(['expires_at' => now()->subMinute()]);
    }

    $again = frpInit($author, $oeuvre, frpSlot())->assertCreated()->json('uuid');

    expect(UploadSession::where('uuid', $again)->value('replaces_media_file_id'))->toBe($removed->id);
})->with(['aborted', 'expired']);

test('the quota is credited on removal and reserved on the replacement init', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    frpFile($oeuvre, frpSlot(), ReviewStatusEnum::ACCEPTED, 'garde.pdf', random_bytes(50));
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'rejete.pdf', random_bytes(70));
    StorageQuota::where('user_id', $author->id)->update(['limit_bytes' => 200]);

    // 120 used: an 81-byte file would not fit before the removal.
    frpRemove($author, $removed)->assertOk();
    expect(frpUsed($author))->toBe(50);

    // 50 used + 150 reserved = the 200 limit exactly: it fits only because
    // the removal gave the 70 bytes back.
    frpInit($author, $oeuvre, frpSlot(), 150)->assertCreated();

    expect((int) InitUpload::liveSessions($author->id)->sum('size_bytes'))->toBe(150)
        ->and(frpUsed($author))->toBe(50);
});

// --- completing a replacement -------------------------------------------------

test('the replacement starts pending, links to the reserved file, and the oeuvre status does not move', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'rejete.pdf', random_bytes(70));
    frpRemove($author, $removed)->assertOk();

    $replacement = frpReplace($author, $oeuvre, frpSlot(), random_bytes(45));

    expect($replacement->replaces_media_file_id)->toBe($removed->id)
        ->and($replacement->review_status)->toBe(ReviewStatusEnum::PENDING)
        ->and($replacement->college_oeuvre_file_id)->toBe(frpSlot()->id)
        ->and($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW)
        ->and(frpUsed($author))->toBe(45)
        ->and(UploadSession::count())->toBe(0);
});

test('CompleteUpload copies the reservation from the session and never recomputes it', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $reserved = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'a.pdf');
    $this->travel(-5)->minutes();
    frpRemove($author, $reserved)->assertOk();
    $this->travelBack();
    $uuid = frpSend($author, $oeuvre, frpSlot(), random_bytes(40));

    // An older open file appears after the init (oldest-first would pick it).
    $older = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'b.pdf');
    frpRemove($author, $older)->assertOk();
    MediaFile::withTrashed()->whereKey($older->id)->update(['deleted_at' => now()->subYear()]);

    $created = test()->postJson("/uploads/{$uuid}/complete")->assertCreated()->json('uuid');

    expect(MediaFile::where('uuid', $created)->value('replaces_media_file_id'))->toBe($reserved->id);
});

test('the accept stays blocked while a replacement is uploading, then while it is pending', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);
    // Every MUSIQUE requirement satisfied but the one being replaced.
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    frpRemove($author, $removed)->assertOk();

    $uuid = frpSend($author, $oeuvre, frpSlot(), random_bytes(40));
    // Uploading: the slot holds no file, so the gate names it.
    $problems = test()->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertUnprocessable()->json('problems');
    expect(collect($problems)->where('code', 'slot_empty')->pluck('params.document_key')->all())->toContain('justificatif_exploitation');

    // Pending: the replacement is in the slot and ready, but not accepted.
    $created = test()->actingAs($author)->postJson("/uploads/{$uuid}/complete")->assertCreated()->json('uuid');
    MediaFile::where('uuid', $created)->update(['status' => MediaFileStatus::READY]);
    $problems = test()->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertUnprocessable()->json('problems');
    expect(collect($problems)->where('code', 'slot_empty')->pluck('params.document_key')->all())->not->toContain('justificatif_exploitation');

    expect(ApproveOeuvre::filesNotAccepted($oeuvre->fresh()))->toBe([['name' => 'nouveau.pdf', 'reviewStatus' => 'pending']])
        ->and($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);
});

test('the decision summary lists open replacement slots as an advisory, never as a blocker', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);
    frpRemove($author, frpFile($oeuvre, frpSlot('file_editeur'), ReviewStatusEnum::REJECTED, 'editeur.pdf'))->assertOk();

    test()->actingAs($admin)->getJson(route('admin.oeuvres.decision', $oeuvre))
        ->assertOk()
        ->assertJsonPath('checklist.openReplacementSlots', [trim(frpSlot('file_editeur')->title_global)]);
});

test('a required slot left open is a blocker, so the advisory does not repeat it', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);
    frpRemove($author, frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED))->assertOk();
    $label = trim(frpSlot()->title_global);

    test()->actingAs($admin)->getJson(route('admin.oeuvres.decision', $oeuvre))
        ->assertOk()
        ->assertJsonPath('checklist.openReplacementSlots', [])
        ->assertJsonFragment(['requiredMissing' => [$label]]);
});

// --- the in-flight race -------------------------------------------------------

test('a deposit decided mid-upload: the next chunk answers 409 oeuvre_decided, aborts, and releases', function (OeuvreStatus $decided) {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    frpRemove($author, $removed)->assertOk();
    $bytes = random_bytes(64);

    $uuid = frpInit($author, $oeuvre, frpSlot(), strlen($bytes))->assertCreated()->json('uuid');
    frpChunk($uuid, 0, substr($bytes, 0, 32))->assertOk();

    Oeuvre::whereKey($oeuvre->id)->update(['status' => $decided]);

    frpChunk($uuid, 1, substr($bytes, 32))->assertStatus(409)->assertJson(['error' => 'oeuvre_decided']);

    expect(UploadSession::where('uuid', $uuid)->value('status'))->toBe(UploadSessionStatus::ABORTED)
        ->and((int) InitUpload::liveSessions($author->id)->sum('size_bytes'))->toBe(0)
        ->and(MediaFile::where('replaces_media_file_id', $removed->id)->exists())->toBeFalse();
})->with([
    'registered' => [OeuvreStatus::REGISTERED],
    'rejected' => [OeuvreStatus::REJECTED],
]);

test('a deposit decided between the last chunk and complete: 409 oeuvre_decided, nothing added', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
    frpRemove($author, $removed)->assertOk();
    $uuid = frpSend($author, $oeuvre, frpSlot(), random_bytes(40));
    $before = frpUsed($author);

    Oeuvre::whereKey($oeuvre->id)->update(['status' => OeuvreStatus::REGISTERED]);

    test()->postJson("/uploads/{$uuid}/complete")->assertStatus(409)->assertJson(['error' => 'oeuvre_decided']);

    expect(UploadSession::where('uuid', $uuid)->value('status'))->toBe(UploadSessionStatus::ABORTED)
        ->and(MediaFile::where('replaces_media_file_id', $removed->id)->exists())->toBeFalse()
        ->and(frpUsed($author))->toBe($before);
});

test('a draft upload that completes after the deposit went under review is refused with 409', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, null, OeuvreStatus::DRAFT);
    $uuid = frpSend($author, $oeuvre, frpSlot(), random_bytes(40));

    Oeuvre::whereKey($oeuvre->id)->update(['status' => OeuvreStatus::UNDER_REVIEW, 'submitted_at' => now()]);

    test()->postJson("/uploads/{$uuid}/complete")->assertStatus(409)->assertJson(['error' => 'oeuvre_submitted']);

    expect($oeuvre->mediaFiles()->count())->toBe(0)
        ->and(UploadSession::where('uuid', $uuid)->value('status'))->toBe(UploadSessionStatus::ABORTED);
});

// --- notifying the officer ----------------------------------------------------

test('the holder is notified once, only when the replacement reaches ready', function () {
    Notification::fake();
    $author = frpAuthor();
    $holder = frpAdmin('Amina');
    $bystander = frpAdmin('Karim');
    $oeuvre = frpOeuvre($author, $holder);
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'ancien.pdf');
    frpRemove($author, $removed)->assertOk();

    $replacement = frpReplace($author, $oeuvre, frpSlot());
    Notification::assertNothingSent();

    app(TransitionMediaFileStatus::class)->handle($replacement->fresh(), MediaFileStatus::READY);
    // A pipeline retry reaching ready again sends nothing new.
    app(TransitionMediaFileStatus::class)->handle($replacement->fresh(), MediaFileStatus::READY);

    Notification::assertSentToTimes($holder, ReplacementFileUploaded::class, 1);
    Notification::assertNotSentTo($bystander, ReplacementFileUploaded::class);
    Notification::assertSentTo($holder, ReplacementFileUploaded::class, function (ReplacementFileUploaded $notification) use ($holder, $oeuvre, $replacement) {
        $data = $notification->toArray($holder);

        expect($notification)->toBeInstanceOf(ShouldQueue::class)
            ->and($notification->via($holder))->toBe(['database'])
            ->and($notification->viaQueues())->toBe(['database' => 'default'])
            ->and($data['type'])->toBe('replacement_uploaded')
            ->and($data['oeuvre_uuid'])->toBe($oeuvre->uuid)
            ->and($data['file_uuid'])->toBe($replacement->uuid)
            ->and($data['replaced_file_name'])->toBe('ancien.pdf')
            ->and($data['reason'])->toBe(FRP_REASON)
            ->and($data['author'])->toBe('Author Person')
            ->and($data['url'])->toContain($replacement->uuid)
            // Nothing private to the author: no email, no quota, no ids.
            ->and(json_encode($data))->not->toContain('@')
            ->and($data)->not->toHaveKeys(['author_id', 'email', 'used_bytes']);

        return true;
    });
});

test('with no holder, every admin is notified', function () {
    Notification::fake();
    $author = frpAuthor();
    $a = frpAdmin('A');
    $b = frpAdmin('B');
    $oeuvre = frpOeuvre($author, null);
    frpRemove($author, frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED))->assertOk();
    $replacement = frpReplace($author, $oeuvre, frpSlot());

    app(TransitionMediaFileStatus::class)->handle($replacement->fresh(), MediaFileStatus::READY);

    Notification::assertSentToTimes($a, ReplacementFileUploaded::class, 1);
    Notification::assertSentToTimes($b, ReplacementFileUploaded::class, 1);
    Notification::assertNotSentTo($author, ReplacementFileUploaded::class);
});

test('an ordinary file reaching ready notifies nobody', function () {
    Notification::fake();
    $oeuvre = frpOeuvre(frpAuthor(), frpAdmin());
    $file = frpFile($oeuvre, frpSlot());
    $file->status = MediaFileStatus::PROCESSING;
    $file->save();

    app(TransitionMediaFileStatus::class)->handle($file->fresh(), MediaFileStatus::READY);

    Notification::assertNothingSent();
});

// --- the pages ----------------------------------------------------------------

test('the author page exposes the review state, the flags and the open slots', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $accepted = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::ACCEPTED, 'ok.pdf');
    $rejected = frpFile($oeuvre, frpSlot('file_editeur'), ReviewStatusEnum::REJECTED, 'non.pdf');
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'parti.pdf');
    frpRemove($author, $removed)->assertOk();

    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('mediaFiles.0.uuid', $accepted->uuid)
            ->where('mediaFiles.0.review_status', 'accepted')
            ->where('mediaFiles.0.can_remove', false)
            ->where('mediaFiles.1.review_status', 'rejected')
            ->where('mediaFiles.1.review_reason', FRP_REASON)
            ->where('mediaFiles.1.can_remove', true)
            ->missing('mediaFiles.1.reviewed_by')
            ->where('replacement.open', [(string) frpSlot()->id => 1])
            ->where('replacement.rejected', 1)
            ->where('replacement.pending', 0));

    // No reviewer identity anywhere on the author's page.
    expect(test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))->getContent())->not->toContain('Officer');

    frpReplace($author, $oeuvre, frpSlot());

    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('replacement.open', [])
            ->where('replacement.pending', 1)
            ->where('mediaFiles.2.replaces.original_name', 'parti.pdf'));
});

test('the admin page shows the replacement link, the removed files and the pending count', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'parti.pdf');
    frpRemove($author, $removed)->assertOk();
    $replacement = frpReplace($author, $oeuvre, frpSlot());

    test()->actingAs($admin)->get(route('admin.oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('files.0.uuid', $replacement->uuid)
            ->where('files.0.replaces.original_name', 'parti.pdf')
            ->where('files.0.replaces.review_reason', FRP_REASON)
            ->where('removed_files.0.uuid', $removed->uuid)
            ->where('removed_files.0.replaced_by.uuid', $replacement->uuid)
            ->where('review_counts.pending_replacements', 1));

    test()->actingAs($admin)->get(route('admin.oeuvres.files.review', ['oeuvre' => $oeuvre, 'mediaFile' => $replacement]))
        ->assertInertia(fn ($page) => $page
            ->where('file.replaces.original_name', 'parti.pdf')
            ->where('file.replaces.review_reason', FRP_REASON)
            // "Suivant à examiner" walks the live files: the replacement is one.
            ->where('siblings.0.uuid', $replacement->uuid)
            ->where('siblings.0.review_status', 'pending'));
});

test('the author dashboard shows "replace", then "awaiting"', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);

    test()->actingAs($author)->get(route('author.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('actions.items.0.uuid', $oeuvre->uuid)
            ->where('actions.items.0.kind', 'replace')
            ->where('actions.items.0.replacement', ['to_replace' => 1, 'pending' => 0]));

    frpRemove($author, $removed)->assertOk();
    frpReplace($author, $oeuvre, frpSlot());

    test()->actingAs($author)->get(route('author.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('actions.items.0.kind', 'awaiting')
            ->where('actions.items.0.replacement', ['to_replace' => 0, 'pending' => 1]));
});

test('the author and admin pages cost a fixed number of queries, whatever the number of replacements', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $measure = function () use ($author, $admin, $oeuvre, &$queries): array {
        $queries = 0;
        test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))->assertOk();
        $authorPage = $queries;

        $queries = 0;
        test()->actingAs($admin)->get(route('admin.oeuvres.show', $oeuvre))->assertOk();

        return [$authorPage, $queries];
    };

    $round = function () use ($author, $oeuvre): void {
        $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED);
        frpRemove($author, $removed)->assertOk();
        frpReplace($author, $oeuvre, frpSlot());
    };

    $round();
    $measure(); // warm-up: permission cache
    $one = $measure();

    $round();
    $round();
    $round();
    $four = $measure();

    expect($four)->toBe($one)
        ->and($one[0])->toBeLessThanOrEqual(40)
        ->and($one[1])->toBeLessThanOrEqual(40);
});

// --- the author withdraws their own replacement ----------------------------

/** A removed rejected file and its replacement, the pipeline done. */
function frpReplaced(User $author, Oeuvre $oeuvre, MediaFileStatus $status = MediaFileStatus::READY): array
{
    $removed = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::REJECTED, 'ancien.pdf');
    frpRemove($author, $removed)->assertOk();
    $replacement = frpReplace($author, $oeuvre, frpSlot());
    $replacement->status = $status;
    $replacement->save();

    return [$removed, $replacement];
}

test('the author withdraws a pending replacement; the slot reopens for the same removed file', function (MediaFileStatus $status) {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    [$removed, $replacement] = frpReplaced($author, $oeuvre, $status);

    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page->where('mediaFiles.0.can_remove', true));

    frpRemove($author, $replacement)->assertOk();

    $withdrawn = MediaFile::withTrashed()->findOrFail($replacement->id);
    expect($withdrawn->trashed())->toBeTrue()
        ->and($withdrawn->purged_at)->not->toBeNull()
        ->and(Storage::disk('vault')->exists($replacement->path))->toBeFalse();

    // A new replacement links to the same removed rejected file.
    $uuid = frpInit($author, $oeuvre, frpSlot())->assertCreated()->json('uuid');
    expect(UploadSession::where('uuid', $uuid)->value('replaces_media_file_id'))->toBe($removed->id);
})->with([
    'ready' => [MediaFileStatus::READY],
    'failed' => [MediaFileStatus::FAILED],
    'quarantined' => [MediaFileStatus::QUARANTINED],
]);

test('after a withdrawal, a second replacement completes and links to the same file', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    [$removed, $first] = frpReplaced($author, $oeuvre);
    frpRemove($author, $first)->assertOk();

    $second = frpReplace($author, $oeuvre, frpSlot());

    expect($second->replaces_media_file_id)->toBe($removed->id)
        ->and(MediaFile::where('replaces_media_file_id', $removed->id)->count())->toBe(1)
        ->and(MediaFile::withTrashed()->where('replaces_media_file_id', $removed->id)->count())->toBe(2);
});

test('two live replacements for one removed file are impossible', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    frpReplaced($author, $oeuvre);

    // The removed file has its live replacement: nothing is open.
    frpInit($author, $oeuvre, frpSlot())->assertUnprocessable();

    expect(MediaFile::openReplacements($oeuvre->id, frpSlot()->id)->count())->toBe(0);
});

test('a removed replacement that was itself rejected carries the chain: one open slot, not two', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);
    [$original, $replacement] = frpReplaced($author, $oeuvre);

    test()->actingAs($admin)->postJson(route('admin.oeuvres.files.decide', ['oeuvre' => $oeuvre, 'mediaFile' => $replacement]), [
        'decision' => 'rejected', 'reason' => FRP_REASON,
    ])->assertOk();
    frpRemove($author, $replacement->fresh())->assertOk();

    $open = MediaFile::openReplacements($oeuvre->id, frpSlot()->id)->pluck('id')->all();
    expect($open)->toBe([$replacement->id]);

    $uuid = frpInit($author, $oeuvre, frpSlot())->assertCreated()->json('uuid');
    expect(UploadSession::where('uuid', $uuid)->value('replaces_media_file_id'))->toBe($replacement->id);
    frpInit($author, $oeuvre, frpSlot())->assertUnprocessable();
});

test('an accepted file, a pending original and another author\'s replacement are not removable', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    $accepted = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::ACCEPTED, 'accepte.pdf');
    $pending = frpFile($oeuvre, frpSlot(), ReviewStatusEnum::PENDING, 'attente.pdf');
    [, $replacement] = frpReplaced($author, $oeuvre);

    frpRemove($author, $accepted)->assertForbidden();
    frpRemove($author, $pending)->assertForbidden();
    frpRemove(frpAuthor(), $replacement)->assertForbidden();

    expect($accepted->fresh()->trashed())->toBeFalse()
        ->and($pending->fresh()->trashed())->toBeFalse()
        ->and($replacement->fresh()->trashed())->toBeFalse();

    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('mediaFiles.0.can_remove', false)
            ->where('mediaFiles.1.can_remove', false));
});

test('a replacement the officer has just accepted answers 409 file_not_removable', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);
    [, $replacement] = frpReplaced($author, $oeuvre);

    // Committed-state order: the acceptance lands first.
    test()->actingAs($admin)->postJson(route('admin.oeuvres.files.decide', ['oeuvre' => $oeuvre, 'mediaFile' => $replacement]), ['decision' => 'accepted'])->assertOk();

    frpRemove($author, $replacement)->assertStatus(409)->assertJson(['error' => RemovalConflict::NOT_REMOVABLE]);

    expect($replacement->fresh()->trashed())->toBeFalse();
});

test('inside the lock: an acceptance after the page loaded refuses the removal', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, frpAdmin());
    [, $replacement] = frpReplaced($author, $oeuvre);
    $stale = $replacement->fresh()->load('oeuvre');

    MediaFile::whereKey($replacement->id)->update(['review_status' => ReviewStatusEnum::ACCEPTED]);

    expect(fn () => app(RemoveMediaFile::class)->handle($stale))
        ->toThrow(fn (RemovalConflict $e) => expect($e->kind)->toBe(RemovalConflict::NOT_REMOVABLE));
    expect($replacement->fresh()->trashed())->toBeFalse();
});

test('the admin page lists a withdrawn replacement among the removed files', function () {
    $author = frpAuthor();
    $admin = frpAdmin();
    $oeuvre = frpOeuvre($author, $admin);
    [$removed, $replacement] = frpReplaced($author, $oeuvre);
    frpRemove($author, $replacement)->assertOk();

    test()->actingAs($admin)->get(route('admin.oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('removed_files.0.uuid', $removed->uuid)
            ->where('removed_files.0.replaced_by.uuid', $replacement->uuid)
            ->where('removed_files.0.replaced_by.removed', true)
            ->where('removed_files.1.uuid', $replacement->uuid)
            ->where('removed_files.1.withdrawn_replacement', true));
});

// --- the author's summary and history -----------------------------------------

test('the author page carries the counters, each slot\'s state and the history, signed ONDA', function () {
    $author = frpAuthor();
    $admin = frpAdmin('Amina Officer');
    $oeuvre = frpOeuvre($author, $admin);
    $accepted = frpFile($oeuvre, frpSlot('autorisation_compositeur'), ReviewStatusEnum::ACCEPTED, 'accepte.pdf');
    $toFix = frpFile($oeuvre, frpSlot('file_editeur'), ReviewStatusEnum::PENDING, 'editeur.pdf');
    test()->actingAs($admin)->postJson(route('admin.oeuvres.files.decide', ['oeuvre' => $oeuvre, 'mediaFile' => $toFix]), [
        'decision' => 'rejected', 'reason' => FRP_REASON,
    ])->assertOk();
    [$removed] = frpReplaced($author, $oeuvre);

    $response = test()->actingAs($author)->get(route('oeuvres.show', $oeuvre));

    $response->assertInertia(fn ($page) => $page
        ->where('review_summary.accepted', 1)
        ->where('review_summary.waiting', 1)
        ->where('review_summary.to_fix', 1)
        ->where('review_summary.slots.'.frpSlot('autorisation_compositeur')->id, 'accepted')
        ->where('review_summary.slots.'.frpSlot('file_editeur')->id, 'to_fix')
        ->where('review_summary.slots.'.frpSlot()->id, 'waiting')
        ->where('history.'.frpSlot('file_editeur')->id.'.0.kind', 'uploaded')
        ->where('history.'.frpSlot('file_editeur')->id.'.1.kind', 'rejected')
        ->where('history.'.frpSlot('file_editeur')->id.'.1.reason', FRP_REASON)
        ->where('history.'.frpSlot()->id.'.0.kind', 'uploaded')
        ->where('history.'.frpSlot()->id.'.1.kind', 'removed')
        ->where('history.'.frpSlot()->id.'.2.kind', 'replacement')
        ->whereNot('review_summary.last_upload_at', null));

    // No reviewer identity anywhere on the author's page.
    expect($response->getContent())->not->toContain('Amina')
        ->and($response->getContent())->not->toContain('reviewer');
});

test('outside review there is no summary', function () {
    $author = frpAuthor();
    $oeuvre = frpOeuvre($author, null, OeuvreStatus::DRAFT);

    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page->where('review_summary', null)->where('history', []));
});
