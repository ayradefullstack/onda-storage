<?php

declare(strict_types=1);

use App\Domain\Access\SignedMediaUrl;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Value\UploadIntent;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Covers the P6-scoped preview/stream read path only (see the phase report
 * for what's deliberately deferred: full-download tickets, admin
 * watermarking, production delivery). Every fixture goes through the REAL
 * `VaultContract` — the same pre-allocate/write-chunk/finalize path a real
 * upload uses — so these prove the full round trip through HTTP, not just
 * that a mocked status string renders. `WorksStatusTest.php` established
 * this same technique for the P5 pipeline; this file's fixture is
 * deliberately identical in shape.
 */
function streamTestAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function streamTestMediaFile(User $author, Work $work, string $status = 'ready'): MediaFile
{
    $vault = app(VaultContract::class);
    $bytes = file_get_contents(base_path('tests/fixtures/sample.mp4'));

    $session = $vault->beginUpload(new UploadIntent($work->id, $author->id, 'sample.mp4', strlen($bytes)));
    $vault->writeChunk($session, 0, $bytes);
    $object = $vault->finalize($session->fresh());

    $mediaFile = new MediaFile;
    $mediaFile->uuid = (string) Str::uuid7();
    $mediaFile->work_id = $work->id;
    $mediaFile->uploaded_by = $author->id;
    $mediaFile->original_name = 'sample.mp4';
    $mediaFile->extension = 'mp4';
    $mediaFile->mime = 'video/mp4';
    $mediaFile->size_bytes = $object->sizeBytes;
    $mediaFile->disk = $object->disk;
    $mediaFile->path = $object->path;
    $mediaFile->sha256_plain = hash('sha256', $bytes);
    $mediaFile->dek_wrapped = $object->dekWrapped;
    $mediaFile->nonce = $object->nonce;
    $mediaFile->mac_path = $object->macPath;
    $mediaFile->status = $status;
    $mediaFile->ref_count = 1;
    $mediaFile->save();

    return $mediaFile;
}

function streamTestCleanup(MediaFile $mediaFile): void
{
    $mediaFile->refresh();
    $disk = Storage::disk($mediaFile->disk);
    @unlink($disk->path($mediaFile->path));
    @unlink($disk->path($mediaFile->mac_path));
}

test('streams the full file with the exact original bytes', function () {
    $author = streamTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = streamTestMediaFile($author, $work);
    $original = file_get_contents(base_path('tests/fixtures/sample.mp4'));

    $url = SignedMediaUrl::forStreaming($mediaFile, $author);

    $response = $this->actingAs($author)->get($url);

    $response->assertOk()
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('Content-Length', (string) strlen($original));
    expect($response->streamedContent())->toBe($original);

    streamTestCleanup($mediaFile);
});

test('a Range request returns 206 with exactly the requested bytes', function () {
    $author = streamTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = streamTestMediaFile($author, $work);
    $original = file_get_contents(base_path('tests/fixtures/sample.mp4'));

    $url = SignedMediaUrl::forStreaming($mediaFile, $author);

    $response = $this->actingAs($author)
        ->withHeaders(['Range' => 'bytes=100-199'])
        ->get($url);

    $response->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 100-199/'.strlen($original))
        ->assertHeader('Content-Length', '100');
    expect($response->streamedContent())->toBe(substr($original, 100, 100));

    streamTestCleanup($mediaFile);
});

test('a Range start not on a 16-byte cipher block boundary still decrypts correctly', function () {
    $author = streamTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = streamTestMediaFile($author, $work);
    $original = file_get_contents(base_path('tests/fixtures/sample.mp4'));

    $url = SignedMediaUrl::forStreaming($mediaFile, $author);

    // 137 is not a multiple of 16 — proves the controller's use of
    // ByteRange/readRange correctly discards the alignment prefix rather
    // than just trusting the domain layer's own (already-covered) unit
    // tests to be exercised the same way over HTTP.
    $response = $this->actingAs($author)
        ->withHeaders(['Range' => 'bytes=137-159'])
        ->get($url);

    $response->assertStatus(206);
    expect($response->streamedContent())->toBe(substr($original, 137, 23));

    streamTestCleanup($mediaFile);
});

test('an expired signature is rejected', function () {
    $author = streamTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = streamTestMediaFile($author, $work);

    $expiredUrl = URL::temporarySignedRoute(
        'media.stream',
        now()->subMinute(),
        ['mediaFile' => $mediaFile->uuid, 'u' => $author->id],
    );

    $this->actingAs($author)->get($expiredUrl)->assertForbidden();

    streamTestCleanup($mediaFile);
});

test('an author cannot stream another author\'s deposit', function () {
    $owner = streamTestAuthor();
    $other = streamTestAuthor();
    $work = Work::factory()->create(['author_id' => $owner->id]);
    $mediaFile = streamTestMediaFile($owner, $work);

    // A validly-signed link for the owner, opened from a different
    // authenticated session — the signature alone doesn't prove who's
    // asking, which is exactly why the ownership policy check exists
    // independent of it.
    $url = SignedMediaUrl::forStreaming($mediaFile, $owner);

    $this->actingAs($other)->get($url)->assertForbidden();

    streamTestCleanup($mediaFile);
});

test('a deposit still mid-pipeline cannot be streamed', function () {
    $author = streamTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = streamTestMediaFile($author, $work, status: 'scanning');

    $url = SignedMediaUrl::forStreaming($mediaFile, $author);

    $this->actingAs($author)->get($url)->assertNotFound();

    streamTestCleanup($mediaFile);
});

test('a successful stream writes a chained file_access_logs row', function () {
    $author = streamTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = streamTestMediaFile($author, $work);

    $url = SignedMediaUrl::forStreaming($mediaFile, $author);
    $this->actingAs($author)->get($url)->assertOk();

    $log = FileAccessLog::where('media_file_id', $mediaFile->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('stream')
        ->and($log->user_id)->toBe($author->id)
        ->and($log->row_hash)->toHaveLength(64);

    streamTestCleanup($mediaFile);
});

test('media.link issues a url only for a ready deposit the requester owns', function () {
    $author = streamTestAuthor();
    $other = streamTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $ready = streamTestMediaFile($author, $work);

    $this->actingAs($author)
        ->getJson(route('media.link', $ready))
        ->assertOk()
        ->assertJsonStructure(['url']);

    $this->actingAs($other)
        ->getJson(route('media.link', $ready))
        ->assertForbidden();

    streamTestCleanup($ready);

    $notReady = streamTestMediaFile($author, $work, status: 'processing');

    $this->actingAs($author)
        ->getJson(route('media.link', $notReady))
        ->assertNotFound();

    streamTestCleanup($notReady);
});
