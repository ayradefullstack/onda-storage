<?php

declare(strict_types=1);

use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Value\StoredObject;
use App\Models\MediaFile;
use App\Models\UploadSession;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/**
 * Small, test-friendly chunk/segment sizes (multiples of the AES block size)
 * instead of the 8 MiB / 1 MiB production defaults — mirrors
 * tests/Unit/Vault/EncryptedLocalVaultTest.php's makeSmallVault(). Must be
 * set before the first HTTP request in a test, since VaultContract is a
 * container singleton that reads config('vault.chunk_size') once, in its
 * constructor.
 */
function useSmallChunks(int $chunkSize = 32, int $segmentSize = 16): void
{
    config(['vault.chunk_size' => $chunkSize, 'vault.mac_segment_size' => $segmentSize]);
}

function authorUser(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function chunkServer(string $bytes, array $extra = []): array
{
    return array_merge([
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CHUNK_CRC32' => hash('crc32b', $bytes),
    ], $extra);
}

function decryptMediaFile(MediaFile $mediaFile): string
{
    $object = new StoredObject(
        uuid: $mediaFile->uuid,
        disk: $mediaFile->disk,
        path: $mediaFile->path,
        macPath: $mediaFile->mac_path,
        dekWrapped: $mediaFile->dek_wrapped,
        nonce: $mediaFile->nonce,
        sizeBytes: $mediaFile->size_bytes,
    );

    $tempFile = app(VaultContract::class)->decryptToTemp($object);

    return file_get_contents($tempFile->path);
}

test('a full 3-chunk lifecycle round-trips the exact source bytes', function () {
    useSmallChunks();
    $user = authorUser();
    $work = Work::factory()->create(['author_id' => $user->id]);

    $plaintext = random_bytes(74); // 32 + 32 + 10 (short final chunk)

    $init = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => strlen($plaintext),
        'mime' => 'video/mp4',
    ]);

    $init->assertCreated();
    $uuid = $init->json('uuid');
    expect($init->json('total_chunks'))->toBe(3);

    foreach ([0, 1, 2] as $index) {
        $chunk = substr($plaintext, $index * 32, 32);
        $response = $this->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], chunkServer($chunk), $chunk);
        $response->assertOk();
        expect($response->json('index'))->toBe($index);
    }

    $session = UploadSession::where('uuid', $uuid)->first();
    expect($session->received_chunks)->toBe(3)
        ->and($session->received_bytes)->toBe(74);

    $complete = $this->postJson("/uploads/{$uuid}/complete");
    $complete->assertCreated();

    $mediaFile = MediaFile::where('uuid', $complete->json('uuid'))->firstOrFail();
    expect($mediaFile->status)->toBe('scanning')
        ->and($mediaFile->size_bytes)->toBe(74)
        ->and($mediaFile->sha256_plain)->toBeNull();

    expect(decryptMediaFile($mediaFile))->toBe($plaintext);

    expect(UploadSession::where('uuid', $uuid)->exists())->toBeFalse();

    @unlink(Storage::disk($mediaFile->disk)->path($mediaFile->path));
    @unlink(Storage::disk($mediaFile->disk)->path($mediaFile->mac_path));
});

test('chunks arriving out of order still assemble the correct file', function () {
    useSmallChunks();
    $user = authorUser();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $plaintext = random_bytes(96); // 3 full 32-byte chunks

    $init = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => strlen($plaintext),
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');

    foreach ([2, 0, 1] as $index) {
        $chunk = substr($plaintext, $index * 32, 32);
        $this->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], chunkServer($chunk), $chunk)
            ->assertOk();
    }

    $complete = $this->postJson("/uploads/{$uuid}/complete")->assertCreated();
    $mediaFile = MediaFile::where('uuid', $complete->json('uuid'))->firstOrFail();

    expect(decryptMediaFile($mediaFile))->toBe($plaintext);

    @unlink(Storage::disk($mediaFile->disk)->path($mediaFile->path));
    @unlink(Storage::disk($mediaFile->disk)->path($mediaFile->mac_path));
});

test('resending an already-received chunk is a no-op and does not double-count bytes', function () {
    useSmallChunks();
    $user = authorUser();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $plaintext = random_bytes(64);

    $init = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => strlen($plaintext),
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');
    $chunk0 = substr($plaintext, 0, 32);

    $this->call('POST', "/uploads/{$uuid}/chunk/0", [], [], [], chunkServer($chunk0), $chunk0)->assertOk();

    $session = UploadSession::where('uuid', $uuid)->first();
    expect($session->received_bytes)->toBe(32)
        ->and($session->received_chunks)->toBe(1);

    // Resend the identical chunk (simulating a client retry after a lost ack).
    $again = $this->call('POST', "/uploads/{$uuid}/chunk/0", [], [], [], chunkServer($chunk0), $chunk0);
    $again->assertOk();

    $session->refresh();
    expect($session->received_bytes)->toBe(32)
        ->and($session->received_chunks)->toBe(1);
});

test('a final chunk shorter than chunk_size is accepted', function () {
    useSmallChunks();
    $user = authorUser();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $plaintext = random_bytes(40); // 1 full chunk (32) + 1 short final chunk (8)

    $init = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => strlen($plaintext),
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');

    $final = substr($plaintext, 32, 8);
    $response = $this->call('POST', "/uploads/{$uuid}/chunk/1", [], [], [], chunkServer($final), $final);

    $response->assertOk();
});

test('a non-final chunk shorter than chunk_size is rejected', function () {
    useSmallChunks();
    $user = authorUser();
    $work = Work::factory()->create(['author_id' => $user->id]);

    $init = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => 64,
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');

    $short = random_bytes(20); // chunk 0 of 2 must be exactly 32 bytes
    $response = $this->call('POST', "/uploads/{$uuid}/chunk/0", [], [], [], chunkServer($short), $short);

    $response->assertStatus(422);
});
