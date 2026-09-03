<?php

declare(strict_types=1);

namespace App\Domain\Vault\Value;

/**
 * The declared shape of an upload a caller wants to begin — everything
 * `VaultContract::beginUpload()` needs before any bytes have arrived.
 */
final readonly class UploadIntent
{
    public function __construct(
        public int $workId,
        public int $userId,
        public string $filename,
        public int $sizeBytes,
    ) {}
}
