<?php

declare(strict_types=1);

use App\Actions\Upload\InitUpload;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\RegisterTypeCollege;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Permission\Models\Role;

/**
 * The client now keeps every file of a deposit uploading at once, so the
 * server sees several upload sessions for one user and one oeuvre with their
 * chunks interleaved, and several inits arriving together. A single PHP test
 * process cannot run two requests truly in parallel; these tests pin the
 * *interleaving* (what the server must tolerate) and the *reservation*
 * (what the second of two near-simultaneous inits must be told), which is
 * the order-of-events a real race produces.
 */
function concurrentAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function concurrentChunk(string $uuid, int $index, string $bytes)
{
    return test()->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CHUNK_CRC32' => hash('crc32b', $bytes),
    ], $bytes);
}

function concurrentInit(Oeuvre $oeuvre, string $filename, int $size, array $extra = [])
{
    return test()->postJson('/uploads', array_merge([
        'oeuvre_id' => $oeuvre->id,
        'filename' => $filename,
        'size_bytes' => $size,
        'mime' => 'video/mp4',
    ], $extra));
}

test('two sessions for the same user and oeuvre accept interleaved chunks, and both complete with the right hash', function () {
    config(['vault.chunk_size' => 4096, 'vault.mac_segment_size' => 1024]);

    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    $this->actingAs($user);

    $first = (string) file_get_contents(base_path('tests/fixtures/sample.mp4'));
    // Same container, different trailing bytes: a different hash, so the two
    // files cannot be collapsed by deduplication.
    $second = $first.str_repeat("\0", 16);

    $a = concurrentInit($oeuvre, 'a.mp4', strlen($first))->assertCreated();
    $b = concurrentInit($oeuvre, 'b.mp4', strlen($second))->assertCreated();
    $uuidA = $a->json('uuid');
    $uuidB = $b->json('uuid');
    expect($uuidA)->not->toBe($uuidB);

    $chunksA = str_split($first, 4096);
    $chunksB = str_split($second, 4096);
    expect(count($chunksA))->toBeGreaterThan(2);

    // A and B alternate, B's chunks in reverse order: no per-user or
    // per-oeuvre ordering is imposed on writes.
    $max = max(count($chunksA), count($chunksB));

    for ($i = 0; $i < $max; $i++) {
        if (isset($chunksA[$i])) {
            concurrentChunk($uuidA, $i, $chunksA[$i])->assertOk();
        }

        $j = count($chunksB) - 1 - $i;

        if ($j >= 0) {
            concurrentChunk($uuidB, $j, $chunksB[$j])->assertOk();
        }
    }

    $mediaA = MediaFile::where('uuid', $this->postJson("/uploads/{$uuidA}/complete")->assertCreated()->json('uuid'))->firstOrFail();
    $mediaB = MediaFile::where('uuid', $this->postJson("/uploads/{$uuidB}/complete")->assertCreated()->json('uuid'))->firstOrFail();

    // QUEUE_CONNECTION=sync: the processing chain has run by now.
    expect($mediaA->fresh()->sha256_plain)->toBe(hash('sha256', $first))
        ->and($mediaB->fresh()->sha256_plain)->toBe(hash('sha256', $second))
        ->and($mediaA->oeuvre_id)->toBe($oeuvre->id)
        ->and($mediaB->oeuvre_id)->toBe($oeuvre->id);

    foreach ([$mediaA, $mediaB] as $media) {
        $media->refresh();
        @unlink(Storage::disk($media->disk)->path($media->path));
        @unlink(Storage::disk($media->disk)->path($media->mac_path));
    }
});

test('concurrent inits that together exceed the quota: exactly one is accepted', function () {
    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    StorageQuota::factory()->create(['user_id' => $user->id, 'limit_bytes' => 1000, 'used_bytes' => 0]);
    $this->actingAs($user);

    // Each fits on its own (600 <= 1000); together they do not. Nothing has
    // completed, so `used_bytes` is still 0 when the second arrives — only
    // the reservation made by the first init can refuse it.
    $first = concurrentInit($oeuvre, 'one.mp4', 600);
    $second = concurrentInit($oeuvre, 'two.mp4', 600);

    $first->assertCreated();
    $second->assertStatus(413);
    expect($second->json('remaining_bytes'))->toBe(400)
        ->and(UploadSession::where('user_id', $user->id)->count())->toBe(1);
});

test('a reservation is released by an abort, and by expiry', function () {
    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    StorageQuota::factory()->create(['user_id' => $user->id, 'limit_bytes' => 1000, 'used_bytes' => 0]);
    $this->actingAs($user);

    $held = concurrentInit($oeuvre, 'one.mp4', 600)->assertCreated()->json('uuid');
    concurrentInit($oeuvre, 'two.mp4', 600)->assertStatus(413);

    $this->deleteJson("/uploads/{$held}")->assertOk();
    $again = concurrentInit($oeuvre, 'two.mp4', 600)->assertCreated()->json('uuid');

    UploadSession::where('uuid', $again)->update(['expires_at' => now()->subMinute()]);
    concurrentInit($oeuvre, 'three.mp4', 600)->assertCreated();
});

test('one user\'s reservation never counts against another user', function () {
    $user = concurrentAuthor();
    $other = concurrentAuthor();
    $mine = Oeuvre::factory()->create(['author_id' => $user->id]);
    $theirs = Oeuvre::factory()->create(['author_id' => $other->id]);
    StorageQuota::factory()->create(['user_id' => $user->id, 'limit_bytes' => 1000, 'used_bytes' => 0]);
    StorageQuota::factory()->create(['user_id' => $other->id, 'limit_bytes' => 1000, 'used_bytes' => 0]);

    $this->actingAs($user);
    concurrentInit($mine, 'one.mp4', 900)->assertCreated();

    $this->actingAs($other);
    concurrentInit($theirs, 'one.mp4', 900)->assertCreated();
});

test('two inits into the same single-file slot: the first is accepted, the second is refused as occupied', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $user = concurrentAuthor();
    $college = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();
    $oeuvre = Oeuvre::factory()->create([
        'author_id' => $user->id,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
    ]);
    /** @var CollegeOeuvreFile $requirement */
    $requirement = $college->collegeOeuvreFiles()->where('document_key', 'paroles')->firstOrFail();
    $requirement->update(['allows_multiple' => false]);

    $this->actingAs($user);
    $payload = ['college_oeuvre_file_id' => $requirement->id, 'mime' => 'application/pdf'];

    concurrentInit($oeuvre, 'one.pdf', 100, $payload)->assertCreated();
    concurrentInit($oeuvre, 'two.pdf', 100, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('college_oeuvre_file_id');

    expect(UploadSession::where('oeuvre_id', $oeuvre->id)->count())->toBe(1);
});

test('the init lock is released afterwards and chunk writes take no user-level lock', function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);

    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    $this->actingAs($user);

    $uuid = concurrentInit($oeuvre, 'a.mp4', 32)->assertCreated()->json('uuid');

    // Held by someone else for this user: a chunk must still go through,
    // proving the chunk path does not wait on the init lock.
    $lock = Cache::lock('upload-init:'.$user->id, 30);
    expect($lock->get())->toBeTrue();

    concurrentChunk($uuid, 0, str_repeat('x', 32))->assertOk();

    $lock->release();

    // And with the lock free again, the next init is not blocked.
    concurrentInit($oeuvre, 'b.mp4', 32)->assertCreated();
});

test('retrying a chunk on the same session does not grow the reservation or create a session', function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);

    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    StorageQuota::factory()->create(['user_id' => $user->id, 'limit_bytes' => 1000, 'used_bytes' => 0]);
    $this->actingAs($user);

    $uuid = concurrentInit($oeuvre, 'one.mp4', 64)->assertCreated()->json('uuid');

    // A failed attempt (corrupt in transit: CRC mismatch), then the retry
    // and a status poll — the client's resume path — all on the same uuid.
    $good = str_repeat('a', 32);
    $this->call('POST', "/uploads/{$uuid}/chunk/0", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CHUNK_CRC32' => '00000000',
    ], $good)->assertStatus(422);
    concurrentChunk($uuid, 0, $good)->assertOk();
    concurrentChunk($uuid, 0, $good)->assertOk(); // a repeat is idempotent
    $this->getJson("/uploads/{$uuid}")->assertOk();

    expect(UploadSession::where('user_id', $user->id)->count())->toBe(1);

    // Reserved is exactly the one session's 64 bytes: 936 fits, 937 does not.
    concurrentInit($oeuvre, 'two.mp4', 937)->assertStatus(413);
    concurrentInit($oeuvre, 'two.mp4', 936)->assertCreated();
});

test('cancelling mid-upload (after chunks have landed) releases the reservation', function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);

    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    StorageQuota::factory()->create(['user_id' => $user->id, 'limit_bytes' => 1000, 'used_bytes' => 0]);
    $this->actingAs($user);

    $uuid = concurrentInit($oeuvre, 'one.mp4', 640)->assertCreated()->json('uuid');
    concurrentChunk($uuid, 0, str_repeat('a', 32))->assertOk();
    concurrentInit($oeuvre, 'two.mp4', 600)->assertStatus(413);

    $this->deleteJson("/uploads/{$uuid}")->assertOk();

    expect(UploadSession::where('uuid', $uuid)->value('status'))->toBe('aborted');
    concurrentInit($oeuvre, 'two.mp4', 600)->assertCreated();
});

test('the init lock is released even when the critical section throws', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $user = concurrentAuthor();
    $college = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();
    $oeuvre = Oeuvre::factory()->create([
        'author_id' => $user->id,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
    ]);
    StorageQuota::factory()->create(['user_id' => $user->id, 'limit_bytes' => 1000, 'used_bytes' => 0]);
    $this->actingAs($user);

    // A ValidationException (no slot chosen) and an HttpResponseException
    // (quota) both escape from INSIDE the locked section.
    concurrentInit($oeuvre, 'one.pdf', 100, ['mime' => 'application/pdf'])->assertUnprocessable();
    $lock = Cache::lock('upload-init:'.$user->id, 1);
    expect($lock->get())->toBeTrue();
    $lock->release();

    $requirement = $college->collegeOeuvreFiles()->where('document_key', 'paroles')->firstOrFail();
    concurrentInit($oeuvre, 'big.pdf', 5000, ['college_oeuvre_file_id' => $requirement->id, 'mime' => 'application/pdf'])->assertStatus(413);
    $lock = Cache::lock('upload-init:'.$user->id, 1);
    expect($lock->get())->toBeTrue();
    $lock->release();
});

test('a lock timeout is a 503 with Retry-After, never a 4xx', function () {
    Sleep::fake(syncWithCarbon: true);

    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    $this->actingAs($user);

    $held = Cache::lock('upload-init:'.$user->id, 30);
    expect($held->get())->toBeTrue();

    concurrentInit($oeuvre, 'one.mp4', 100)
        ->assertStatus(503)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error', 'init_busy');

    $held->release();
    concurrentInit($oeuvre, 'one.mp4', 100)->assertCreated();
});

test('only live sessions count as reserved: expired, aborted and completed ones do not', function () {
    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    StorageQuota::factory()->create(['user_id' => $user->id, 'limit_bytes' => 1000, 'used_bytes' => 0]);
    $this->actingAs($user);

    $expired = concurrentInit($oeuvre, 'a.mp4', 300)->assertCreated()->json('uuid');
    $aborted = concurrentInit($oeuvre, 'b.mp4', 300)->assertCreated()->json('uuid');
    $completed = concurrentInit($oeuvre, 'c.mp4', 300)->assertCreated()->json('uuid');
    $live = concurrentInit($oeuvre, 'd.mp4', 100)->assertCreated()->json('uuid');

    // 300 + 300 + 300 + 100 = 1000 reserved: the quota is exactly full.
    concurrentInit($oeuvre, 'e.mp4', 1)->assertStatus(413);

    UploadSession::where('uuid', $expired)->update(['expires_at' => now()->subSecond()]);
    UploadSession::where('uuid', $aborted)->update(['status' => 'aborted']);
    UploadSession::where('uuid', $completed)->update(['status' => 'completed']);

    expect(InitUpload::liveSessions($user->id)->pluck('uuid')->all())->toBe([$live]);

    // Only the live 100 bytes remain reserved: 900 fits, 901 does not.
    concurrentInit($oeuvre, 'f.mp4', 901)->assertStatus(413);
    concurrentInit($oeuvre, 'f.mp4', 900)->assertCreated();
});

test('a quota rejection carries the mapped status, the error key, and both numbers the client shows', function () {
    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    StorageQuota::factory()->create(['user_id' => $user->id, 'limit_bytes' => 1000, 'used_bytes' => 100]);
    $this->actingAs($user);

    concurrentInit($oeuvre, 'one.mp4', 500)->assertCreated();

    concurrentInit($oeuvre, 'two.mp4', 600)
        ->assertStatus(413)
        ->assertJsonPath('error', 'quota_exceeded')
        ->assertJsonPath('remaining_bytes', 400)
        ->assertJsonPath('needed_bytes', 600);
});

test('chunk traffic cannot drain the init limiter: they no longer share a bucket', function () {
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);

    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    $this->actingAs($user);

    $uuid = concurrentInit($oeuvre, 'a.mp4', 32)->assertCreated()->json('uuid');

    // The old `throttle:10,1` init limit shared a counter with the chunk
    // route, so a running upload made the next init a 429 within a second.
    for ($i = 0; $i < 25; $i++) {
        concurrentChunk($uuid, 0, str_repeat('x', 32))->assertOk();
    }

    concurrentInit($oeuvre, 'b.mp4', 32)->assertCreated();
});

test('the upload routes use named limiters whose keys never collide', function () {
    $routes = Route::getRoutes();

    expect($routes->getByName('uploads.init')->gatherMiddleware())->toContain('throttle:upload-init')
        ->and($routes->getByName('uploads.chunk')->gatherMiddleware())->toContain('throttle:upload-chunk');

    $user = concurrentAuthor();
    $request = Request::create('/uploads/abc/chunk/0', 'POST');
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => (new LaravelRoute('POST', '/uploads/{session}/chunk/{index}', []))
        ->bind($request));

    $init = RateLimiter::limiter('upload-init')($request);
    $chunk = RateLimiter::limiter('upload-chunk')($request);

    expect($init->key)->toStartWith('init:')
        ->and(collect($chunk)->map(fn ($limit) => $limit->key)->all())->not->toContain($init->key)
        ->and($chunk[0]->key)->toContain('abc')
        // Sized for `maxInFlight` chunks at full local speed.
        ->and($chunk[0]->maxAttempts)->toBeGreaterThanOrEqual(1200);
});

test('uploads:release-stale aborts only live-but-idle sessions, through the abort path', function () {
    $user = concurrentAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    StorageQuota::factory()->create(['user_id' => $user->id, 'limit_bytes' => 1000, 'used_bytes' => 0]);
    $this->actingAs($user);

    $idle = concurrentInit($oeuvre, 'idle.mp4', 600)->assertCreated()->json('uuid');
    $fresh = concurrentInit($oeuvre, 'fresh.mp4', 300)->assertCreated()->json('uuid');
    UploadSession::where('uuid', $idle)->update(['updated_at' => now()->subHours(2)]);

    $this->artisan('uploads:release-stale --dry-run')->assertSuccessful();
    expect(UploadSession::where('uuid', $idle)->value('status'))->toBe('uploading');

    $this->artisan('uploads:release-stale --idle=30')
        ->expectsOutputToContain('Released 1 stale session(s), 600 reserved bytes.')
        ->assertSuccessful();

    expect(UploadSession::where('uuid', $idle)->value('status'))->toBe('aborted')
        ->and(UploadSession::where('uuid', $fresh)->value('status'))->toBe('uploading');

    // The 600 bytes are free again: 700 fits next to the fresh 300.
    concurrentInit($oeuvre, 'next.mp4', 700)->assertCreated();
});
