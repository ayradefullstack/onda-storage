<?php

declare(strict_types=1);

use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\RegisterTypeCollege;
use App\Models\UploadSession;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 2: a classified oeuvre's files are uploaded into a required-document
 * slot (`college_oeuvre_files`) and validated against that slot, through the
 * same chunked pipeline as before. The unclassified path (global whitelist)
 * is covered unchanged by the rest of tests/Feature/Upload.
 */
beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
    // Small chunks, as in UploadLifecycleTest — must precede the first request.
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);
});

function slotAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function slotOeuvre(User $author, string $codeCollege): Oeuvre
{
    $college = RegisterTypeCollege::where('code_college', $codeCollege)->firstOrFail();

    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
    ]);
}

function slotRequirement(string $codeCollege, string $documentKey): CollegeOeuvreFile
{
    return RegisterTypeCollege::where('code_college', $codeCollege)->firstOrFail()
        ->collegeOeuvreFiles()->where('document_key', $documentKey)->firstOrFail();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return TestResponse<Response>
 */
function slotInit(Oeuvre $oeuvre, ?int $requirementId, string $filename, int $sizeBytes, array $overrides = []): TestResponse
{
    return test()->postJson('/uploads', [
        'oeuvre_id' => $oeuvre->id,
        'college_oeuvre_file_id' => $requirementId,
        'filename' => $filename,
        'size_bytes' => $sizeBytes,
        'mime' => 'application/octet-stream',
        ...$overrides,
    ]);
}

/** Init → every chunk → complete, over HTTP, exactly as the browser does it. */
function slotUpload(Oeuvre $oeuvre, CollegeOeuvreFile $requirement, string $filename, string $bytes): MediaFile
{
    $uuid = slotInit($oeuvre, $requirement->id, $filename, strlen($bytes))->assertCreated()->json('uuid');

    foreach (str_split($bytes, 32) as $index => $chunk) {
        test()->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHUNK_CRC32' => hash('crc32b', $chunk),
        ], $chunk)->assertOk();
    }

    $complete = test()->postJson("/uploads/{$uuid}/complete")->assertCreated();

    return MediaFile::where('uuid', $complete->json('uuid'))->firstOrFail();
}

function slotCleanup(MediaFile $mediaFile): void
{
    $mediaFile->refresh();
    $disk = Storage::disk($mediaFile->disk);
    @unlink($disk->path($mediaFile->path));
    @unlink($disk->path($mediaFile->mac_path));
}

// --- the requirement carried onto the MediaFile ----------------------------------

test('a file uploaded to a slot stores college_oeuvre_file_id and the document key snapshot', function () {
    Bus::fake();
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'MUSIQUE');
    $requirement = slotRequirement('MUSIQUE', 'justificatif_exploitation');

    $this->actingAs($author);
    $mediaFile = slotUpload($oeuvre, $requirement, 'contrat.pdf', random_bytes(70));

    expect($mediaFile->college_oeuvre_file_id)->toBe($requirement->id)
        ->and($mediaFile->document_key_snapshot)->toBe('justificatif_exploitation')
        ->and($mediaFile->mime)->toBe('application/pdf')
        ->and(UploadSession::count())->toBe(0);

    slotCleanup($mediaFile);
});

test('the snapshot keeps the key as of the upload after the requirement is re-keyed', function () {
    Bus::fake();
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'MUSIQUE');
    $requirement = slotRequirement('MUSIQUE', 'paroles');

    $this->actingAs($author);
    $mediaFile = slotUpload($oeuvre, $requirement, 'paroles.pdf', random_bytes(40));

    $requirement->update(['document_key' => 'paroles_renamed']);

    expect($mediaFile->fresh()->document_key_snapshot)->toBe('paroles')
        ->and($mediaFile->fresh()->collegeOeuvreFile->document_key)->toBe('paroles_renamed');

    slotCleanup($mediaFile);
});

test('a full upload through the real pipeline reaches ready with the requirement set', function () {
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'MUSIQUE');
    $requirement = slotRequirement('MUSIQUE', 'justificatif_exploitation');

    // A REAL PDF, not random bytes: the chain now includes
    // VerifyContentType, which reads the actual content and refuses
    // anything libmagic cannot identify as a type the slot accepts. Random
    // bytes named .PDF are exactly what that job exists to reject, so this
    // fixture is what keeps the test end-to-end rather than weakening the
    // check to let it through.
    $bytes = file_get_contents(base_path('tests/fixtures/formats/sample.pdf'));

    $this->actingAs($author);
    // No Bus::fake(): QUEUE_CONNECTION=sync runs the whole P5 chain.
    $mediaFile = slotUpload($oeuvre, $requirement, 'Justificatif.PDF', $bytes)->fresh();

    expect($mediaFile->status)->toBe('ready')
        ->and($mediaFile->sha256_plain)->toBe(hash('sha256', $bytes))
        ->and($mediaFile->college_oeuvre_file_id)->toBe($requirement->id)
        ->and($mediaFile->document_key_snapshot)->toBe('justificatif_exploitation')
        ->and($oeuvre->requiredDocumentsProgress()['satisfied'])->toBe(1);

    slotCleanup($mediaFile);
});

// --- validation against the slot -------------------------------------------------

test('an extension outside the slot list is rejected at init, naming the accepted formats', function () {
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'MUSIQUE');

    $this->actingAs($author);
    slotInit($oeuvre, slotRequirement('MUSIQUE', 'paroles')->id, 'paroles.mp3', 100)
        ->assertUnprocessable()
        ->assertJsonPath('errors.filename.0', 'The file type ".mp3" is not accepted for this document. Accepted formats: PDF.');

    expect(UploadSession::count())->toBe(0);
});

test('the same extension in uppercase is accepted', function () {
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'MUSIQUE');

    $this->actingAs($author);
    slotInit($oeuvre, slotRequirement('MUSIQUE', 'paroles')->id, 'PAROLES_FINAL.PDF', 100)->assertCreated();
    slotInit($oeuvre, slotRequirement('MUSIQUE', 'enregistrement_oeuvre')->id, 'Master.Wav', 100)->assertCreated();

    expect(UploadSession::whereNotNull('college_oeuvre_file_id')->count())->toBe(2);
});

test('a file over the slot max_size_kb is rejected, and one within it is accepted', function () {
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'ARTS_GRAPHIQUES');
    $requirement = slotRequirement('ARTS_GRAPHIQUES', 'oeuvres_plastiques_graphiques');

    expect($requirement->max_size_kb)->toBe(102400);

    $this->actingAs($author);
    slotInit($oeuvre, $requirement->id, 'toile.png', 102400 * 1024 + 1)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('size_bytes');

    slotInit($oeuvre, $requirement->id, 'toile.png', 2048)->assertCreated();
});

test('a classified oeuvre requires a slot', function () {
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'MUSIQUE');

    $this->actingAs($author);
    slotInit($oeuvre, null, 'movie.mp4', 100, ['mime' => 'video/mp4'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('college_oeuvre_file_id');
});

test('an unclassified oeuvre refuses a slot and keeps the global whitelist', function () {
    $author = slotAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);

    $this->actingAs($author);
    slotInit($oeuvre, slotRequirement('MUSIQUE', 'paroles')->id, 'paroles.pdf', 100, ['mime' => 'application/pdf'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('college_oeuvre_file_id');

    slotInit($oeuvre, null, 'paroles.pdf', 100, ['mime' => 'application/pdf'])->assertCreated();
    slotInit($oeuvre, null, 'photo.jpg', 100, ['mime' => 'image/jpeg'])->assertUnprocessable();
});

test('svg and xml are stored as application/octet-stream so they can never render inline', function () {
    Bus::fake();
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'LOGICIEL');

    $this->actingAs($author);
    $svg = slotUpload($oeuvre, slotRequirement('LOGICIEL', 'schema_bdd'), 'schema.SVG', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $xml = slotUpload($oeuvre, slotRequirement('LOGICIEL', 'code_source'), 'pom.xml', '<project/>');

    expect($svg->mime)->toBe('application/octet-stream')
        ->and($xml->mime)->toBe('application/octet-stream');

    slotCleanup($svg);
    slotCleanup($xml);
});

// --- the cross-collège drift case ------------------------------------------------

test('a requirement from another collège is rejected: the file-requirement pair must share the oeuvre collège', function () {
    // The interesting part: both ids are individually valid. The oeuvre is a
    // real MUSIQUE oeuvre the author owns; the requirement is a real,
    // un-retired row — even with the SAME document_key MUSIQUE itself has
    // (`justificatif_exploitation`). Only the pair is wrong, and no foreign
    // key can express that `media_files.college_oeuvre_file_id` must belong
    // to the collège of its own `oeuvre_id`. A correct UI never sends this;
    // a crafted request will, so InitUpload must refuse it.
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'MUSIQUE');
    $foreign = slotRequirement('EDITEUR_MUSICAL', 'justificatif_exploitation');

    expect(CollegeOeuvreFile::whereKey($foreign->id)->exists())->toBeTrue()
        ->and($foreign->register_type_college_id)->not->toBe($oeuvre->register_type_college_id);

    $this->actingAs($author);
    slotInit($oeuvre, $foreign->id, 'contrat.pdf', 100)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('college_oeuvre_file_id');

    // Nothing was pre-allocated, and the invariant holds across every file.
    $drifted = DB::table('media_files')
        ->join('oeuvres', 'oeuvres.id', '=', 'media_files.oeuvre_id')
        ->join('college_oeuvre_files', 'college_oeuvre_files.id', '=', 'media_files.college_oeuvre_file_id')
        ->whereColumn('college_oeuvre_files.register_type_college_id', '!=', 'oeuvres.register_type_college_id')
        ->count();

    expect(UploadSession::count())->toBe(0)
        ->and($drifted)->toBe(0);
});

test('a retired requirement is rejected at init', function () {
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'MUSIQUE');
    $requirement = slotRequirement('MUSIQUE', 'paroles');
    $requirement->delete();

    $this->actingAs($author);
    slotInit($oeuvre, $requirement->id, 'paroles.pdf', 100)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('college_oeuvre_file_id');
});

// --- allows_multiple = false: refuse, never replace ----------------------------

test('a single-file slot refuses a second file while one is deposited or in flight, and allows a retry after failure', function () {
    Bus::fake();
    $author = slotAuthor();
    $oeuvre = slotOeuvre($author, 'MUSIQUE');
    $requirement = slotRequirement('MUSIQUE', 'paroles');
    $requirement->update(['allows_multiple' => false]);

    $this->actingAs($author);

    // An upload still in flight occupies the slot.
    $inFlight = slotInit($oeuvre, $requirement->id, 'draft.pdf', 100)->assertCreated()->json('uuid');
    slotInit($oeuvre, $requirement->id, 'other.pdf', 100)->assertUnprocessable()->assertJsonValidationErrors('college_oeuvre_file_id');
    $this->deleteJson("/uploads/{$inFlight}")->assertOk();

    $first = slotUpload($oeuvre, $requirement, 'paroles.pdf', random_bytes(40));

    slotInit($oeuvre, $requirement->id, 'paroles-v2.pdf', 100)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('college_oeuvre_file_id');

    // Refused, not replaced: the first deposit is untouched.
    expect($first->fresh()->trashed())->toBeFalse();

    $first->forceFill(['status' => 'failed'])->save();

    slotInit($oeuvre, $requirement->id, 'paroles-v2.pdf', 100)->assertCreated();

    slotCleanup($first);
});
