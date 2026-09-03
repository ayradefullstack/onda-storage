<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Work;
use Spatie\Permission\Models\Role;

function authTestAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

beforeEach(function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);
});

test('another author cannot read, write to, or abort someone else\'s upload session', function () {
    $owner = authTestAuthor();
    $stranger = authTestAuthor();
    $work = Work::factory()->create(['author_id' => $owner->id]);

    $init = $this->actingAs($owner)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => 64,
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');

    // Read (status).
    $this->actingAs($stranger)->getJson("/uploads/{$uuid}")->assertForbidden();

    // Write (chunk).
    $bytes = random_bytes(32);
    $this->actingAs($stranger)->call('POST', "/uploads/{$uuid}/chunk/0", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CHUNK_CRC32' => hash('crc32b', $bytes),
    ], $bytes)->assertForbidden();

    // Complete.
    $this->actingAs($stranger)->postJson("/uploads/{$uuid}/complete")->assertForbidden();

    // Abort.
    $this->actingAs($stranger)->deleteJson("/uploads/{$uuid}")->assertForbidden();
});

test('a user cannot init an upload against a work they do not own', function () {
    $owner = authTestAuthor();
    $stranger = authTestAuthor();
    $work = Work::factory()->create(['author_id' => $owner->id]);

    $response = $this->actingAs($stranger)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => 64,
        'mime' => 'video/mp4',
    ]);

    $response->assertForbidden();
});
