<?php

declare(strict_types=1);

use App\Models\UploadSession;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * Backs P4's manual gate item (b) — "killing the network mid-upload and
 * restoring it resumes without data loss" — at the one layer a feature test
 * can actually reach: the server-side contract the frontend's resume logic
 * (`uploadClient.fetchUploadStatus` → `stores/uploads.ts`'s
 * `reconcileAndResume`) depends on. This does not, and cannot, prove the
 * browser-side sessionStorage/File-reselect flow works — that remains
 * unverified except by code reading, and ultimately by manual browser
 * testing.
 */
function resumeStatusAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function resumeStatusChunkServer(string $bytes): array
{
    return [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CHUNK_CRC32' => hash('crc32b', $bytes),
    ];
}

beforeEach(function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);
});

test('GET /uploads/{session} reports exactly the chunks received so far, for a resuming client', function () {
    $user = resumeStatusAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);

    $init = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => 96, // 3 chunks of 32 bytes
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');

    // Simulates "network dropped after chunk 0 and 2 landed, chunk 1 didn't"
    // — an out-of-order partial upload, exactly what a resume has to
    // reconcile against.
    foreach ([0, 2] as $index) {
        $bytes = random_bytes(32);
        $this->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], resumeStatusChunkServer($bytes), $bytes)
            ->assertOk();
    }

    $status = $this->getJson("/uploads/{$uuid}");

    $status->assertOk()
        ->assertJsonPath('status', 'uploading')
        ->assertJsonPath('total_chunks', 3)
        ->assertJsonPath('received_bytes', 64);

    // The resuming client sends only what's missing (index 1) — it needs
    // this exact array, not just a count, to know which indices to skip.
    expect($status->json('received'))->toBe([0, 2]);
});

test('GET /uploads/{session} reflects a fully-received-but-not-yet-completed upload', function () {
    $user = resumeStatusAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);

    $init = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => 64,
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');

    foreach ([0, 1] as $index) {
        $bytes = random_bytes(32);
        $this->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], resumeStatusChunkServer($bytes), $bytes)
            ->assertOk();
    }

    $status = $this->getJson("/uploads/{$uuid}");

    $status->assertOk()
        ->assertJsonPath('status', 'uploading')
        ->assertJsonPath('received_bytes', 64);
    expect($status->json('received'))->toBe([0, 1]);
});

test('a resumed session that has since expired is reported as such, not silently treated as active', function () {
    $user = resumeStatusAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);

    $init = $this->actingAs($user)->postJson('/uploads', [
        'work_id' => $work->id,
        'filename' => 'movie.mp4',
        'size_bytes' => 64,
        'mime' => 'video/mp4',
    ]);
    $uuid = $init->json('uuid');

    UploadSession::where('uuid', $uuid)->update([
        'expires_at' => now()->subMinute(),
    ]);

    // The status endpoint itself doesn't reject an expired session (only
    // chunk uploads do — see ChunkFailureTest) — the frontend's
    // reconcileAndResume() is the thing that reads `status` and expiry and
    // decides to stop; this confirms the data it reads is accurate.
    $status = $this->getJson("/uploads/{$uuid}");
    $status->assertOk();

    expect(Carbon::parse($status->json('expires_at'))->isPast())->toBeTrue();
});
