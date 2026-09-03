<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Value;

use App\Domain\Vault\Value\StoredObject;
use App\Models\MediaFile;

/**
 * `StoredObject` (Domain\Vault) is deliberately not an Eloquent model — it
 * decouples the storage/crypto layer from persistence. This is the one
 * place the pipeline bridges the two.
 */
final class StoredObjectMapper
{
    public static function fromMediaFile(MediaFile $mediaFile): StoredObject
    {
        return new StoredObject(
            uuid: $mediaFile->uuid,
            disk: $mediaFile->disk,
            path: $mediaFile->path,
            macPath: $mediaFile->mac_path,
            dekWrapped: $mediaFile->dek_wrapped,
            nonce: $mediaFile->nonce,
            sizeBytes: $mediaFile->size_bytes,
        );
    }
}
