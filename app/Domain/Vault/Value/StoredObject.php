<?php

declare(strict_types=1);

namespace App\Domain\Vault\Value;

/**
 * A read-only projection of a finalized vault file's crypto-relevant fields.
 * Deliberately not an Eloquent model — it decouples the storage/crypto layer
 * from persistence, so `EncryptedLocalVault` (and a future `EncryptedS3Vault`)
 * can be built and tested without touching `App\Models\MediaFile`.
 */
final readonly class StoredObject
{
    public function __construct(
        public string $uuid,
        public string $disk,
        public string $path,
        public string $macPath,
        public string $dekWrapped,
        public string $nonce,
        public int $sizeBytes,
    ) {}
}
