<?php

declare(strict_types=1);

use App\Models\UploadSession;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

function failureAuthorUser(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function initSessionUuid(User $user, Work $work, int $sizeBytes = 64): string
{
    return test()->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => $sizeBytes,
        'mime' => 'video/mp4',
    ])->json('uuid');
}

beforeEach(function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);
});

test('a CRC32 mismatch returns 422 and does not advance the received mask', function () {
    $user = failureAuthorUser();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $uuid = initSessionUuid($user, $work);

    $bytes = random_bytes(32);

    $response = $this->call('POST', "/uploads/{$uuid}/chunk/0", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CHUNK_CRC32' => 'deadbeef', // deliberately wrong
    ], $bytes);

    $response->assertStatus(422);
    expect($response->json('index'))->toBe(0);

    $session = UploadSession::where('uuid', $uuid)->first();
    expect($session->received_chunks)->toBe(0)
        ->and($session->received_bytes)->toBe(0);
});

test('a chunk sent to an expired session returns 410', function () {
    $user = failureAuthorUser();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $uuid = initSessionUuid($user, $work);

    UploadSession::where('uuid', $uuid)->update(['expires_at' => Carbon::now()->subMinute()]);

    $bytes = random_bytes(32);

    $response = $this->call('POST', "/uploads/{$uuid}/chunk/0", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CHUNK_CRC32' => hash('crc32b', $bytes),
    ], $bytes);

    $response->assertStatus(410);
});

test('a multipart chunk body is rejected with 415', function () {
    $user = failureAuthorUser();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $uuid = initSessionUuid($user, $work);

    $response = $this->actingAs($user)->call('POST', "/uploads/{$uuid}/chunk/0", [], [], [], [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=----XYZ',
        'HTTP_ACCEPT' => 'application/json',
    ], '------XYZ--');

    $response->assertStatus(415);
});

test('a chunk index outside the valid range is rejected with 422', function () {
    $user = failureAuthorUser();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $uuid = initSessionUuid($user, $work); // 64 bytes => 2 chunks, indices 0-1

    $bytes = random_bytes(32);

    $response = $this->call('POST', "/uploads/{$uuid}/chunk/5", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CHUNK_CRC32' => hash('crc32b', $bytes),
    ], $bytes);

    $response->assertStatus(422);
});
