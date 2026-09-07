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
            uuid: self::storedUuidFrom($mediaFile->path),
            disk: $mediaFile->disk,
            path: $mediaFile->path,
            macPath: $mediaFile->mac_path,
            dekWrapped: $mediaFile->dek_wrapped,
            nonce: $mediaFile->nonce,
            sizeBytes: $mediaFile->size_bytes,
        );
    }

    /**
     * `MediaFile.uuid` (the row's own route-key uuid, assigned independently
     * by `HasUuidColumn` when the row is created) is NOT the identifier the
     * bytes on disk were encrypted under — every MAC tag was computed with
     * `UploadSession.uuid` (`EncryptedLocalVault::writeChunk`), and the
     * vault file is literally named after that same session uuid
     * (`finalize()`: `{shard}/{uuid}.bin`). Passing `MediaFile.uuid` to
     * `StoredObject` instead — the two are unrelated after
     * `CompleteUpload` lets `HasUuidColumn` mint the row's own uuid — makes
     * every MAC verification in `readRange()` fail for every real deposit;
     * `decryptToTemp()` never surfaced this because it does not check the
     * MAC at all. Recovering the session uuid from the stored filename
     * fixes this without touching the frozen crypto core, and self-corrects
     * for a deduplicated row too: its `path` points at the *original*
     * upload's file, so this naturally resolves to the uuid whose tags
     * actually cover those bytes.
     */
    private static function storedUuidFrom(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }
}
