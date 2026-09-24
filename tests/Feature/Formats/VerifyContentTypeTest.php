<?php

declare(strict_types=1);

use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\PipelineWorkspace;
use App\Jobs\ComputeContentHash;
use App\Jobs\DecryptToTemp;
use App\Jobs\ProcessMediaFile;
use App\Jobs\VerifyContentType;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\RegisterTypeCollege;
use App\Models\User;
use App\Support\FileFormats;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Content verification, with REAL fixture files rather than synthetic
 * bytes — the whole point is that libmagic reads genuine structure, and a
 * handcrafted header would test the test rather than the detection.
 *
 * Every expected MIME here was measured on this machine (libmagic 545) and
 * is recorded in the Part 0 audit.
 */
beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
    Notification::fake();
});

function formatFixture(string $name): string
{
    return base_path("tests/fixtures/formats/{$name}");
}

/**
 * A media file whose decrypted plaintext is already staged on the `work`
 * disk, which is the state DecryptToTemp leaves behind — so the job under
 * test runs against exactly what it would see in production.
 */
function stageForVerification(string $fixture, string $storedName, ?CollegeOeuvreFile $slot = null): MediaFile
{
    $author = User::factory()->withRole('author')->create();

    $oeuvre = Oeuvre::factory()->create([
        'author_id' => $author->id,
        'register_type_college_id' => $slot?->register_type_college_id,
    ]);

    $extension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));

    $mediaFile = MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'original_name' => $storedName,
        'extension' => $extension,
        'mime' => FileFormats::storedMimeFor($extension) ?? 'application/octet-stream',
        'college_oeuvre_file_id' => $slot?->id,
        'status' => MediaFileStatus::SCANNING,
        'size_bytes' => filesize(formatFixture($fixture)),
    ]);

    Storage::disk('work')->put(
        basename(PipelineWorkspace::tempPath($mediaFile->uuid)),
        file_get_contents(formatFixture($fixture)),
    );

    return $mediaFile;
}

function slotFor(string $code, string $documentKey): CollegeOeuvreFile
{
    return RegisterTypeCollege::where('code_college', $code)->firstOrFail()
        ->collegeOeuvreFiles()->where('document_key', $documentKey)->firstOrFail();
}

/** Runs the job the way the queue does, so `failed()` fires on failure. */
function runVerification(MediaFile $mediaFile): void
{
    $job = new VerifyContentType($mediaFile->uuid);

    try {
        $job->handle();
    } catch (Throwable $e) {
        $job->failed($e);
    }
}

// --- the happy paths ----------------------------------------------------

test('a real PDF in a PDF slot passes', function () {
    $slot = slotFor('MUSIQUE', 'justificatif_exploitation');
    expect($slot->extensions)->toContain('pdf');

    $mediaFile = stageForVerification('sample.pdf', 'justificatif.pdf', $slot);

    runVerification($mediaFile);

    expect($mediaFile->fresh()->status)->toBe(MediaFileStatus::SCANNING)
        ->and($mediaFile->fresh()->mime)->toBe('application/pdf');
});

test('a real DOCX passes in a slot that accepts DOCX, whatever MIME finfo returns here', function () {
    $slot = slotFor('MUSIQUE', 'justificatif_exploitation');
    $slot->extensions = ['pdf', 'docx'];
    $slot->save();

    // The measured value on this machine; older libmagic returns
    // application/zip, and the registry lists both.
    $detected = finfo_file(finfo_open(FILEINFO_MIME_TYPE), formatFixture('sample.docx'));
    expect($slot->fresh()->mime_types)->toContain($detected);

    $mediaFile = stageForVerification('sample.docx', 'contrat.docx', $slot->fresh());

    runVerification($mediaFile);

    expect($mediaFile->fresh()->status)->toBe(MediaFileStatus::SCANNING);
});

test('an uppercase .MOV still passes where mov is listed', function () {
    $slot = slotFor('MUSIQUE', 'enregistrement_oeuvre');
    $slot->extensions = ['mov'];
    $slot->save();

    $mediaFile = stageForVerification('sample.mov', 'IMG_0967.MOV', $slot->fresh());

    expect($mediaFile->extension)->toBe('mov');

    runVerification($mediaFile);

    expect($mediaFile->fresh()->status)->toBe(MediaFileStatus::SCANNING)
        ->and($mediaFile->fresh()->mime)->toBe('video/quicktime');
});

test('an unclassified oeuvre is checked against the whole registry', function () {
    $mediaFile = stageForVerification('sample.png', 'scan.png', null);

    expect($mediaFile->college_oeuvre_file_id)->toBeNull();

    runVerification($mediaFile);

    expect($mediaFile->fresh()->status)->toBe(MediaFileStatus::SCANNING);
});

// --- the renamed-file cases, which are the point -------------------------

test('a DOCX renamed to .pdf in a PDF slot fails, naming both types', function () {
    $slot = slotFor('MUSIQUE', 'justificatif_exploitation');

    // The filename claims PDF; the bytes are a DOCX. InitUpload cannot
    // catch this — only the content check can.
    $mediaFile = stageForVerification('sample.docx', 'paroles.pdf', $slot);

    runVerification($mediaFile);

    expect($mediaFile->fresh()->status)->toBe(MediaFileStatus::FAILED)
        // Failed, NOT quarantined: quarantine means suspected malware, and
        // this is the author's mistake to fix.
        ->and($mediaFile->fresh()->status)->not->toBe(MediaFileStatus::QUARANTINED);
});

test('a Windows executable renamed to .pdf fails', function () {
    $slot = slotFor('MUSIQUE', 'justificatif_exploitation');

    $mediaFile = stageForVerification('sample.exe', 'justificatif.pdf', $slot);

    runVerification($mediaFile);

    expect($mediaFile->fresh()->status)->toBe(MediaFileStatus::FAILED);
});

test('the mismatch reason names the real type and the expected one', function () {
    $slot = slotFor('MUSIQUE', 'justificatif_exploitation');
    $mediaFile = stageForVerification('sample.docx', 'paroles.pdf', $slot);

    $reason = null;

    try {
        (new VerifyContentType($mediaFile->uuid))->handle();
    } catch (Throwable $e) {
        $reason = $e->getMessage();
    }

    // The exception message is what MarkMediaFileFailed writes to the log,
    // so it has to read as something an author can act on: both types
    // named, and what to do about it.
    expect($reason)->not->toBeNull()
        ->and($reason)->toContain('paroles.pdf')
        // The real type, in words rather than the 78-character OpenXML MIME.
        ->and($reason)->toContain('DOCX')
        // And what the slot actually wanted.
        ->and($reason)->toContain('PDF')
        ->and($reason)->toContain('renamed');

    $detected = finfo_file(finfo_open(FILEINFO_MIME_TYPE), formatFixture('sample.docx'));

    expect($detected)->not->toBe('application/pdf')
        ->and(FileFormats::mimesFor('pdf'))->not->toContain($detected);
});

test('a file libmagic cannot identify at all is refused', function () {
    $slot = slotFor('MUSIQUE', 'justificatif_exploitation');
    $mediaFile = stageForVerification('sample.pdf', 'justificatif.pdf', $slot);

    // Replace the staged plaintext with bytes nothing recognises.
    Storage::disk('work')->put(
        basename(PipelineWorkspace::tempPath($mediaFile->uuid)),
        random_bytes(4096),
    );

    runVerification($mediaFile);

    expect($mediaFile->fresh()->status)->toBe(MediaFileStatus::FAILED);
});

// --- the stored-XSS rule survives ----------------------------------------

test('svg and xml are still stored as application/octet-stream', function (string $fixture, string $name, string $extension) {
    $slot = slotFor('MUSIQUE', 'justificatif_exploitation');
    $slot->extensions = [$extension];
    $slot->save();

    $mediaFile = stageForVerification($fixture, $name, $slot->fresh());

    runVerification($mediaFile);

    expect($mediaFile->fresh()->status)->toBe(MediaFileStatus::SCANNING)
        // An author-supplied SVG served as image/svg+xml would execute
        // against the reviewing admin's session.
        ->and($mediaFile->fresh()->mime)->toBe('application/octet-stream');
})->with([
    'svg' => ['sample.svg', 'logo.svg', 'svg'],
    'xml' => ['sample.xml', 'metadata.xml', 'xml'],
]);

// --- the chain ------------------------------------------------------------

test('VerifyContentType sits immediately after DecryptToTemp', function () {
    $chain = array_map(
        fn (object $job): string => $job::class,
        ProcessMediaFile::chainJobs('any-uuid'),
    );

    $decrypt = array_search(DecryptToTemp::class, $chain, true);
    $verify = array_search(VerifyContentType::class, $chain, true);
    $hash = array_search(ComputeContentHash::class, $chain, true);

    expect($verify)->toBe($decrypt + 1)
        // Before the hash, the dedup and the scan: finfo reads only the
        // first bytes, so a wrong file fails before anything expensive.
        ->and($verify)->toBeLessThan($hash);
});

test('a missing temp file throws, so the normal retry applies', function () {
    $slot = slotFor('MUSIQUE', 'justificatif_exploitation');
    $mediaFile = stageForVerification('sample.pdf', 'justificatif.pdf', $slot);

    Storage::disk('work')->delete(basename(PipelineWorkspace::tempPath($mediaFile->uuid)));

    // Transient failures throw; only a genuine mismatch short-circuits.
    expect(fn () => (new VerifyContentType($mediaFile->uuid))->handle())
        ->toThrow(RuntimeException::class);
});
