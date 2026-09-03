<?php

declare(strict_types=1);

use App\Models\StorageQuota;
use App\Models\UploadSession;
use App\Models\User;
use App\Models\Work;
use Spatie\Permission\Models\Role;

function completeTestAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

beforeEach(function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);
});

/**
 * Runs a full init -> chunk(s) -> complete lifecycle for a given byte size
 * and returns the `complete` response. Chunk size is fixed at 32 (set in
 * beforeEach above).
 */
function completeAnUploadOfSize(User $user, Work $work, int $sizeBytes): Illuminate\Testing\TestResponse
{
    $init = test()->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => $sizeBytes,
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');
    $totalChunks = $init->json('total_chunks');

    for ($index = 0; $index < $totalChunks; $index++) {
        $isFinal = $index === $totalChunks - 1;
        $length = $isFinal ? $sizeBytes - ($index * 32) : 32;
        $bytes = random_bytes($length);

        test()->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHUNK_CRC32' => hash('crc32b', $bytes),
        ], $bytes)->assertOk();
    }

    return test()->postJson("/uploads/{$uuid}/complete");
}

test('completing an upload with a missing chunk returns 409 listing the missing index', function () {
    $user = completeTestAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);

    $init = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => 96, // 3 chunks: 0, 1, 2
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');

    // Only send chunk 0 and 2 — chunk 1 never arrives.
    foreach ([0, 2] as $index) {
        $bytes = random_bytes(32);
        $this->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHUNK_CRC32' => hash('crc32b', $bytes),
        ], $bytes)->assertOk();
    }

    $response = $this->postJson("/uploads/{$uuid}/complete");

    $response->assertStatus(409);
    expect($response->json('missing'))->toBe([1]);

    // Session must still exist — an incomplete upload is not consumed.
    expect(UploadSession::where('uuid', $uuid)->exists())->toBeTrue();
});

test('an upload that would exceed the storage quota is blocked at init with the remaining bytes', function () {
    $user = completeTestAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);

    StorageQuota::factory()->create([
        'user_id' => $user->id,
        'limit_bytes' => 1000,
        'used_bytes' => 900,
    ]);

    $response = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => 500, // 900 + 500 > 1000
        'mime' => 'video/mp4',
    ]);

    $response->assertStatus(413);
    expect($response->json('remaining_bytes'))->toBe(100);

    expect(UploadSession::where('work_id', $work->id)->exists())->toBeFalse();
});

test('an upload within quota is allowed to init', function () {
    $user = completeTestAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);

    StorageQuota::factory()->create([
        'user_id' => $user->id,
        'limit_bytes' => 1000,
        'used_bytes' => 100,
    ]);

    $response = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => 500,
        'mime' => 'video/mp4',
    ]);

    $response->assertCreated();
});

test('two sequential completes leave used_bytes equal to the exact sum of both file sizes', function () {
    $user = completeTestAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);

    StorageQuota::factory()->create([
        'user_id' => $user->id,
        'limit_bytes' => 1_000_000,
        'used_bytes' => 0,
    ]);

    completeAnUploadOfSize($user, $work, 64)->assertCreated();
    completeAnUploadOfSize($user, $work, 96)->assertCreated();

    $quota = StorageQuota::where('user_id', $user->id)->first();
    expect($quota->used_bytes)->toBe(64 + 96);
});

test('completing an upload for a user with no quota row creates one from the default', function () {
    $user = completeTestAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);

    expect(StorageQuota::where('user_id', $user->id)->exists())->toBeFalse();

    completeAnUploadOfSize($user, $work, 64)->assertCreated();

    $quota = StorageQuota::where('user_id', $user->id)->firstOrFail();
    expect($quota->used_bytes)->toBe(64)
        ->and($quota->limit_bytes)->toBe(App\Domain\Quota\QuotaPolicy::DEFAULT_LIMIT_BYTES);
});

test('a failed complete (missing chunks) does not change used_bytes', function () {
    $user = completeTestAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);

    StorageQuota::factory()->create([
        'user_id' => $user->id,
        'limit_bytes' => 1_000_000,
        'used_bytes' => 500,
    ]);

    $init = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => 96, // 3 chunks
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');

    // Only chunk 0 arrives — 1 and 2 never do.
    $bytes = random_bytes(32);
    $this->call('POST', "/uploads/{$uuid}/chunk/0", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CHUNK_CRC32' => hash('crc32b', $bytes),
    ], $bytes)->assertOk();

    $this->postJson("/uploads/{$uuid}/complete")->assertStatus(409);

    $quota = StorageQuota::where('user_id', $user->id)->first();
    expect($quota->used_bytes)->toBe(500);
});
