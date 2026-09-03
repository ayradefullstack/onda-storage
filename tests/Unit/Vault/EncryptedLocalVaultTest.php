<?php

declare(strict_types=1);

use App\Domain\Vault\Contracts\ChunkTracker;
use App\Domain\Vault\Crypto\CtrCipher;
use App\Domain\Vault\Crypto\KeyManager;
use App\Domain\Vault\Crypto\SegmentMac;
use App\Domain\Vault\Value\ByteRange;
use App\Domain\Vault\Value\StoredObject;
use App\Domain\Vault\Value\UploadIntent;
use App\Infrastructure\Tracker\DatabaseChunkTracker;
use App\Infrastructure\Vault\EncryptedLocalVault;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Builds a vault wired with small, test-friendly chunk/segment sizes (16
 * bytes each) instead of the 8 MiB / 1 MiB production defaults, so these
 * tests can exercise multi-chunk and multi-segment behaviour with tiny
 * fixtures. 16 stays a multiple of the AES block size and of itself.
 */
function makeSmallVault(int $chunkSize = 32, int $segmentSize = 16): array
{
    config(['vault.chunk_size' => $chunkSize, 'vault.mac_segment_size' => $segmentSize]);

    $tracker = new DatabaseChunkTracker;
    $vault = new EncryptedLocalVault(new KeyManager, $tracker, new CtrCipher, new SegmentMac($segmentSize));

    return [$vault, $tracker];
}

function cleanupStoredObject(?StoredObject $object): void
{
    if ($object === null) {
        return;
    }

    @unlink(Storage::disk($object->disk)->path($object->path));
    @unlink(Storage::disk($object->disk)->path($object->macPath));
}

test('a small file round-trips through beginUpload/writeChunk/finalize/readRange', function () {
    [$vault] = makeSmallVault();
    $work = Work::factory()->create();
    $plaintext = random_bytes(64); // 2 chunks of 32 bytes

    $session = $vault->beginUpload(new UploadIntent($work->id, $work->author_id, 'file.bin', strlen($plaintext)));

    $vault->writeChunk($session, 0, substr($plaintext, 0, 32));
    $vault->writeChunk($session, 1, substr($plaintext, 32, 32));

    $object = $vault->finalize($session->fresh());

    try {
        $stream = $vault->readRange($object, null);
        expect($stream->getContents())->toBe($plaintext);
    } finally {
        cleanupStoredObject($object);
    }
});

test('three chunks written out of order (2, 0, 1) produce a correct plaintext file', function () {
    [$vault] = makeSmallVault();
    $work = Work::factory()->create();
    $plaintext = random_bytes(96); // 3 chunks of 32 bytes

    $session = $vault->beginUpload(new UploadIntent($work->id, $work->author_id, 'file.bin', strlen($plaintext)));

    $vault->writeChunk($session, 2, substr($plaintext, 64, 32));
    $vault->writeChunk($session, 0, substr($plaintext, 0, 32));
    $vault->writeChunk($session, 1, substr($plaintext, 32, 32));

    $object = $vault->finalize($session->fresh());

    try {
        $stream = $vault->readRange($object, null);
        expect($stream->getContents())->toBe($plaintext);
    } finally {
        cleanupStoredObject($object);
    }
});

test('a range read spanning a MAC segment boundary returns exactly the right bytes', function () {
    [$vault] = makeSmallVault(chunkSize: 32, segmentSize: 16);
    $work = Work::factory()->create();
    $plaintext = random_bytes(64);

    $session = $vault->beginUpload(new UploadIntent($work->id, $work->author_id, 'file.bin', strlen($plaintext)));
    $vault->writeChunk($session, 0, substr($plaintext, 0, 32));
    $vault->writeChunk($session, 1, substr($plaintext, 32, 32));
    $object = $vault->finalize($session->fresh());

    try {
        // Segments are 16 bytes; [10, 25] spans segment 0 (0-15) and segment 1 (16-31).
        $range = new ByteRange(10, 25);
        $stream = $vault->readRange($object, $range);

        expect($stream->getContents())->toBe(substr($plaintext, 10, 16));
    } finally {
        cleanupStoredObject($object);
    }
});

test('a range read with an unaligned start returns correct bytes', function () {
    [$vault] = makeSmallVault(chunkSize: 32, segmentSize: 16);
    $work = Work::factory()->create();
    $plaintext = random_bytes(64);

    $session = $vault->beginUpload(new UploadIntent($work->id, $work->author_id, 'file.bin', strlen($plaintext)));
    $vault->writeChunk($session, 0, substr($plaintext, 0, 32));
    $vault->writeChunk($session, 1, substr($plaintext, 32, 32));
    $object = $vault->finalize($session->fresh());

    try {
        // Byte 7 is not a multiple of 16 — forces the alignment-prefix path.
        $range = new ByteRange(7, 20);
        $stream = $vault->readRange($object, $range);

        expect($stream->getContents())->toBe(substr($plaintext, 7, 14));
    } finally {
        cleanupStoredObject($object);
    }
});

test('finalize refuses to run while the tracker mask is incomplete', function () {
    [$vault] = makeSmallVault();
    $work = Work::factory()->create();

    $session = $vault->beginUpload(new UploadIntent($work->id, $work->author_id, 'file.bin', 64));
    $vault->writeChunk($session, 0, random_bytes(32));
    // Chunk 1 never arrives.

    expect(fn () => $vault->finalize($session->fresh()))
        ->toThrow(App\Domain\Vault\Exceptions\IncompleteUpload::class);

    // Clean up the still-incoming temp file.
    @unlink(Storage::disk('incoming')->path($session->temp_path));
    @unlink(Storage::disk('incoming')->path($session->temp_path.'.mac'));
});

test('decryptToTemp produces a plaintext TempFile that is removed on destruction', function () {
    [$vault] = makeSmallVault();
    $work = Work::factory()->create();
    $plaintext = random_bytes(64);

    $session = $vault->beginUpload(new UploadIntent($work->id, $work->author_id, 'file.bin', strlen($plaintext)));
    $vault->writeChunk($session, 0, substr($plaintext, 0, 32));
    $vault->writeChunk($session, 1, substr($plaintext, 32, 32));
    $object = $vault->finalize($session->fresh());

    try {
        $tempPath = null;

        (function () use ($vault, $object, $plaintext, &$tempPath) {
            $tempFile = $vault->decryptToTemp($object);
            $tempPath = $tempFile->path;
            expect(file_get_contents($tempFile->path))->toBe($plaintext);
        })();

        expect(is_file($tempPath))->toBeFalse();
    } finally {
        cleanupStoredObject($object);
    }
});
