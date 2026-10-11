<?php

declare(strict_types=1);

use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Value\UploadIntent;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function pipelineAuthor(): User
{
    return User::factory()->withRole('author')->create();
}

/**
 * A real deposited file: the bytes go through the REAL VaultContract (the
 * same pre-allocate / write / finalize path an upload uses) into this test's
 * temporary vault root.
 */
function pipelineFile(User $author, Oeuvre $oeuvre, string $bytes, string $name, string $mime, string $status = 'ready'): MediaFile
{
    $vault = app(VaultContract::class);

    $session = $vault->beginUpload(new UploadIntent($oeuvre->id, $author->id, $name, max(1, strlen($bytes))));
    $vault->writeChunk($session, 0, $bytes);
    $object = $vault->finalize($session->fresh());

    $mediaFile = new MediaFile;
    $mediaFile->uuid = (string) Str::uuid7();
    $mediaFile->oeuvre_id = $oeuvre->id;
    $mediaFile->uploaded_by = $author->id;
    $mediaFile->original_name = $name;
    $mediaFile->extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mediaFile->mime = $mime;
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

function orphanFile(string $relative, int $ageDays): string
{
    $absolute = Storage::disk('vault')->path($relative);
    @mkdir(dirname($absolute), 0700, true);
    file_put_contents($absolute, 'orphan');
    touch($absolute, time() - $ageDays * 86400);

    return $absolute;
}

const ORPHAN_A = 'ab/cd/01a00000-0000-7000-8000-000000000001';
const ORPHAN_B = 'ab/cd/01a00000-0000-7000-8000-000000000002';
