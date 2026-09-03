<?php

declare(strict_types=1);

namespace App\Domain\Vault\Contracts;

use App\Domain\Vault\Value\ByteRange;
use App\Domain\Vault\Value\StoredObject;
use App\Domain\Vault\Value\TempFile;
use App\Domain\Vault\Value\UploadIntent;
use App\Models\UploadSession;
use Psr\Http\Message\StreamInterface;

/**
 * The storage + encryption seam. `EncryptedLocalVault` is the only local
 * implementation; a future `EncryptedS3Vault` implements the same contract
 * without any other class needing to change.
 */
interface VaultContract
{
    public function beginUpload(UploadIntent $intent): UploadSession;

    public function writeChunk(UploadSession $session, int $index, string $bytes): void;

    public function finalize(UploadSession $session): StoredObject;

    public function readRange(StoredObject $object, ?ByteRange $range): StreamInterface;

    public function decryptToTemp(StoredObject $object): TempFile;

    public function destroy(StoredObject $object): void;
}
