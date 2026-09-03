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

test('a missing ffprobe still reaches ready with null metadata', function () {
    // Forces the NullProbe binding regardless of whether ffmpeg happens to
    // be installed on the machine running this test — the scenario under
    // test is "MEDIA_PROBE_DRIVER=null / ffprobe absent", not "this
    // developer's machine doesn't have ffmpeg".
    config(['vault.media_probe_driver' => 'null']);

    Role::findOrCreate('author');
    $author = User::factory()->create();
    $author->assignRole('author');
    $work = Work::factory()->create(['author_id' => $author->id]);

    $vault = app(VaultContract::class);
    $bytes = file_get_contents(base_path('tests/fixtures/sample.mp4'));
    $session = $vault->beginUpload(new UploadIntent($work->id, $author->id, 'no-probe.mp4', strlen($bytes)));
    $vault->writeChunk($session, 0, $bytes);
    $object = $vault->finalize($session->fresh());

    $mediaFile = new MediaFile;
    $mediaFile->uuid = (string) Str::uuid7();
    $mediaFile->work_id = $work->id;
    $mediaFile->uploaded_by = $author->id;
    $mediaFile->original_name = 'no-probe.mp4';
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

    ProcessMediaFile::dispatch($mediaFile->uuid);

    $mediaFile->refresh();

    expect($mediaFile->status)->toBe('ready')
        ->and($mediaFile->sha256_plain)->not->toBeNull()
        ->and($mediaFile->duration_sec)->toBeNull()
        ->and($mediaFile->width)->toBeNull()
        ->and($mediaFile->height)->toBeNull();

    $disk = Storage::disk($mediaFile->disk);
    @unlink($disk->path($mediaFile->path));
    @unlink($disk->path($mediaFile->mac_path));
});
