<?php

declare(strict_types=1);

use App\Domain\Deposit\PipelineWorkspace;
use App\Domain\Deposit\Value\RowHash;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Value\UploadIntent;
use App\Jobs\ComputeContentHash;
use App\Jobs\ProcessMediaFile;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\User;
use App\Models\Work;
use Illuminate\Bus\UniqueLock;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

function failureTestAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function failureTestUploadFixture(User $author, Work $work): MediaFile
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
    $mediaFile->sha256_plain = null;
    $mediaFile->dek_wrapped = $object->dekWrapped;
    $mediaFile->nonce = $object->nonce;
    $mediaFile->mac_path = $object->macPath;
    $mediaFile->status = 'scanning';
    $mediaFile->ref_count = 1;
    $mediaFile->save();

    return $mediaFile;
}

test('an exception mid-chain leaves no file in work/', function () {
    $author = failureTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = failureTestUploadFixture($author, $work);

    // Corrupts the recorded size so ComputeContentHash's own integrity
    // check (decrypted size must equal size_bytes) throws — a real,
    // deterministic mid-chain failure, not a mocked one.
    $mediaFile->size_bytes += 1;
    $mediaFile->save();

    try {
        ProcessMediaFile::dispatch($mediaFile->uuid);
    } catch (Throwable) {
        // QUEUE_CONNECTION=sync re-throws after calling failed() — expected.
    }

    expect(PipelineWorkspace::exists($mediaFile->uuid))->toBeFalse()
        ->and($mediaFile->fresh()->status)->toBe('failed');

    $disk = Storage::disk($mediaFile->disk);
    @unlink($disk->path($mediaFile->path));
    @unlink($disk->path($mediaFile->mac_path));
});

test('two real deposit rows chain: the second row\'s prev_hash is the first row\'s row_hash', function () {
    $author = failureTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFileA = failureTestUploadFixture($author, $work);
    $mediaFileB = failureTestUploadFixture($author, $work);

    ProcessMediaFile::dispatch($mediaFileA->uuid);
    ProcessMediaFile::dispatch($mediaFileB->uuid);

    $rows = FileAccessLog::where('action', 'deposit')
        ->whereIn('media_file_id', [$mediaFileA->fresh()->id, $mediaFileB->fresh()->id])
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->prev_hash)->toBe($rows[0]->row_hash)
        ->and($rows[0]->row_hash)->toHaveLength(64);

    $disk = Storage::disk($mediaFileA->disk);
    @unlink($disk->path($mediaFileA->fresh()->path));
    @unlink($disk->path($mediaFileA->fresh()->mac_path));

    if ($mediaFileB->fresh()->path !== $mediaFileA->fresh()->path) {
        @unlink($disk->path($mediaFileB->fresh()->path));
        @unlink($disk->path($mediaFileB->fresh()->mac_path));
    }
});

test('the hash chain detects a tampered row: recomputing from altered content disagrees with the stored row_hash', function () {
    // A self-contained demonstration of RowHash's tamper-evidence, the same
    // primitive RecordDeposit uses to write real rows (proven separately,
    // above, to actually chain end to end).
    $genesisPayload = ['event' => 'deposit', 'sha256_plain' => str_repeat('a', 64), 'size_bytes' => 1000];
    $rowOneHash = RowHash::compute(null, $genesisPayload);

    $rowTwoPayload = ['event' => 'deposit', 'sha256_plain' => str_repeat('b', 64), 'size_bytes' => 2000];
    $rowTwoHash = RowHash::compute($rowOneHash, $rowTwoPayload);

    // Untampered: row two's hash verifies against row one's real row_hash.
    expect(RowHash::verify($rowOneHash, $rowTwoPayload, $rowTwoHash))->toBeTrue();

    // An attacker edits row one's content directly in storage (e.g. changes
    // its sha256_plain) without recomputing anything downstream.
    $tamperedGenesisPayload = ['event' => 'deposit', 'sha256_plain' => str_repeat('Z', 64), 'size_bytes' => 1000];
    $rowOneHashRecomputedFromTamperedContent = RowHash::compute(null, $tamperedGenesisPayload);

    // Row one's own stored row_hash no longer matches what its (now
    // altered) content actually hashes to...
    expect($rowOneHashRecomputedFromTamperedContent)->not->toBe($rowOneHash);

    // ...and because row two's hash was built from row one's ORIGINAL
    // row_hash, verifying row two against the tampered recomputation also
    // fails — the tamper is detectable from either direction.
    expect(RowHash::verify($rowOneHashRecomputedFromTamperedContent, $rowTwoPayload, $rowTwoHash))->toBeFalse();
});

test('ShouldBeUnique prevents a second dispatch for the same media file uuid', function () {
    $author = failureTestAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);
    $mediaFile = failureTestUploadFixture($author, $work);

    $job = new ComputeContentHash($mediaFile->uuid);
    $lock = Cache::lock(UniqueLock::getKey($job), 10);

    expect($lock->get())->toBeTrue();

    try {
        Event::fake([UniqueJobSkipped::class]);

        ComputeContentHash::dispatch($mediaFile->uuid);

        Event::assertDispatched(UniqueJobSkipped::class);
        expect($mediaFile->fresh()->sha256_plain)->toBeNull();
    } finally {
        $lock->release();
    }

    $disk = Storage::disk($mediaFile->disk);
    @unlink($disk->path($mediaFile->path));
    @unlink($disk->path($mediaFile->mac_path));
});
