<?php

declare(strict_types=1);

use App\Domain\Deposit\PipelineWorkspace;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Value\UploadIntent;
use App\Jobs\CleanupTemp;
use App\Jobs\ComputeContentHash;
use App\Jobs\DecryptToTemp;
use App\Jobs\DeduplicateFile;
use App\Jobs\ExtractMetadata;
use App\Jobs\GenerateVariants;
use App\Jobs\ProcessMediaFile;
use App\Jobs\RecordDeposit;
use App\Jobs\ScanForMalware;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Drives the P3 upload pipeline directly (finalize() etc.) with a small,
 * real MP4 fixture — producing an authentic encrypted MediaFile row exactly
 * like a real upload would, without going through the HTTP layer. Domain
 * Vault/Actions Upload are frozen this phase but freely usable.
 */
function fullChainAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function fullChainFixturePath(): string
{
    return base_path('tests/fixtures/sample.mp4');
}

function fullChainUploadFixture(User $author, Work $work, ?string $filename = null): MediaFile
{
    $vault = app(VaultContract::class);
    $bytes = file_get_contents(fullChainFixturePath());
    $filename ??= 'sample-'.Str::random(8).'.mp4';

    $session = $vault->beginUpload(new UploadIntent($work->id, $author->id, $filename, strlen($bytes)));
    $vault->writeChunk($session, 0, $bytes);
    $object = $vault->finalize($session->fresh());

    $mediaFile = new MediaFile;
    $mediaFile->uuid = (string) Str::uuid7();
    $mediaFile->work_id = $work->id;
    $mediaFile->uploaded_by = $author->id;
    $mediaFile->original_name = $filename;
    $mediaFile->extension = 'mp4';
    $mediaFile->mime = 'video/mp4';
    $mediaFile->size_bytes = $object->sizeBytes;
    $mediaFile->disk = $object->disk;
    $mediaFile->path = $object->path;
    $mediaFile->sha256_plain = null;
    $mediaFile->dek_wrapped = $object->dekWrapped;
    $mediaFile->nonce = $object->nonce;
    $mediaFile->mac_path = $object->macPath;
    $mediaFile->status = 'scanning';
    $mediaFile->ref_count = 1;
    $mediaFile->save();

    return $mediaFile;
}

function fullChainCleanupBytes(MediaFile $mediaFile): void
{
    $disk = Storage::disk($mediaFile->disk);
    @unlink($disk->path($mediaFile->path));
    @unlink($disk->path($mediaFile->mac_path));
}

test('sha256_plain is null before the chain and populated after', function () {
    $author = fullChainAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = fullChainUploadFixture($author, $work);

    expect($mediaFile->sha256_plain)->toBeNull();

    ProcessMediaFile::dispatch($mediaFile->uuid);

    $mediaFile->refresh();

    expect($mediaFile->sha256_plain)->toBe(hash_file('sha256', fullChainFixturePath()));

    fullChainCleanupBytes($mediaFile);
});

test('the full chain on a small real MP4 fixture reaches ready', function () {
    $author = fullChainAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = fullChainUploadFixture($author, $work);

    ProcessMediaFile::dispatch($mediaFile->uuid);

    $mediaFile->refresh();

    expect($mediaFile->status)->toBe('ready')
        ->and($mediaFile->sha256_plain)->not->toBeNull()
        ->and($mediaFile->size_bytes)->toBe((int) filesize(fullChainFixturePath()));

    // ffmpeg IS installed on this machine (winget) and MEDIA_PROBE_DRIVER
    // defaults to ffmpeg, so this asserts the real, non-null path — the
    // null-metadata path is asserted separately in MetadataFallbackTest.
    if (config('vault.media_probe_driver') === 'ffmpeg') {
        expect($mediaFile->duration_sec)->toBe(2)
            ->and($mediaFile->width)->toBe(320)
            ->and($mediaFile->height)->toBe(240);
    }

    // RecordDeposit's audit row.
    $depositLog = FileAccessLog::where('media_file_id', $mediaFile->id)->where('action', 'deposit')->first();
    expect($depositLog)->not->toBeNull()
        ->and($depositLog->row_hash)->toHaveLength(64);

    // CleanupTemp ran — no plaintext survives.
    expect(PipelineWorkspace::exists($mediaFile->uuid))->toBeFalse();

    fullChainCleanupBytes($mediaFile);
});

test('every job timeout stays at or below queue.connections.database.retry_after', function () {
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect($retryAfter)->toBe(3600);

    $timeouts = [
        DecryptToTemp::class => 1800,
        ComputeContentHash::class => 300,
        DeduplicateFile::class => 300,
        ScanForMalware::class => 300,
        ExtractMetadata::class => 300,
        GenerateVariants::class => 3600,
        RecordDeposit::class => 300,
        CleanupTemp::class => 300,
    ];

    foreach ($timeouts as $jobClass => $expectedTimeout) {
        $job = new $jobClass('00000000-0000-7000-8000-000000000000');
        expect($job->timeout)->toBe($expectedTimeout)
            ->and($job->timeout)->toBeLessThanOrEqual($retryAfter);
    }
});
