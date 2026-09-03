<?php

declare(strict_types=1);

use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Value\UploadIntent;
use App\Jobs\ProcessMediaFile;
use App\Models\MediaFile;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

function dedupAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function dedupUploadFixture(User $author, Work $work, string $filename): MediaFile
{
    $vault = app(VaultContract::class);
    $bytes = file_get_contents(base_path('tests/fixtures/sample.mp4'));

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

test('two identical uploads leave one set of bytes on disk with ref_count 2', function () {
    $author = dedupAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);

    $first = dedupUploadFixture($author, $work, 'first-copy.mp4');
    $second = dedupUploadFixture($author, $work, 'second-copy.mp4');

    $firstPathBeforeDedup = $first->path;
    $secondPathBeforeDedup = $second->path;

    ProcessMediaFile::dispatch($first->uuid);
    ProcessMediaFile::dispatch($second->uuid);

    $first->refresh();
    $second->refresh();

    expect($first->status)->toBe('ready')
        ->and($second->status)->toBe('ready')
        ->and($first->sha256_plain)->toBe($second->sha256_plain)
        // The second row now points at the first's bytes, not its own.
        ->and($second->path)->toBe($first->path)
        ->and($second->mac_path)->toBe($first->mac_path)
        ->and($second->dek_wrapped)->toBe($first->dek_wrapped)
        ->and($first->fresh()->ref_count)->toBe(2);

    // The SECOND upload's own originally-finalized bytes were deleted —
    // only one physical copy remains, at the first upload's path.
    $disk = Storage::disk($first->disk);
    expect(is_file($disk->path($firstPathBeforeDedup)))->toBeTrue();
    expect($secondPathBeforeDedup)->not->toBe($firstPathBeforeDedup);
    expect(is_file($disk->path($secondPathBeforeDedup)))->toBeFalse();

    @unlink($disk->path($first->path));
    @unlink($disk->path($first->mac_path));
});

test('deleting one of a deduplicated pair does not delete the shared bytes', function () {
    $author = dedupAuthor();
    $work = Work::factory()->create(['author_id' => $author->id]);

    $first = dedupUploadFixture($author, $work, 'pair-a.mp4');
    $second = dedupUploadFixture($author, $work, 'pair-b.mp4');

    ProcessMediaFile::dispatch($first->uuid);
    ProcessMediaFile::dispatch($second->uuid);

    $first->refresh();
    $second->refresh();

    $disk = Storage::disk($first->disk);
    $sharedAbsolutePath = $disk->path($first->path);
    expect(is_file($sharedAbsolutePath))->toBeTrue();

    // A soft delete (the only kind media_files supports) must not touch the
    // bytes at all — purging them is a separate, later-phase concern gated
    // on ref_count, not something a delete does directly.
    $second->delete();

    expect(is_file($sharedAbsolutePath))->toBeTrue()
        ->and($first->fresh()->status)->toBe('ready');

    @unlink($disk->path($first->path));
    @unlink($disk->path($first->mac_path));
});
