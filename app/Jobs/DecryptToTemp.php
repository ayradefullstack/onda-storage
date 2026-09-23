<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Deposit\PipelineWorkspace;
use App\Domain\Deposit\Value\StoredObjectMapper;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Exceptions\InsufficientStorage;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * First job in the chain: `VaultContract::decryptToTemp()` produces the one
 * plaintext copy the rest of the pipeline reads. This is the moment disk
 * usage peaks — the plaintext temporarily doubles the file's footprint on
 * top of the encrypted original still on the vault disk — so free space is
 * checked first.
 */
final class DecryptToTemp extends PipelineJob
{
    public int $timeout = 1800;

    public function handle(VaultContract $vault): void
    {
        $mediaFile = MediaFile::where('uuid', $this->mediaFileUuid)->firstOrFail();

        $this->assertFreeSpace($mediaFile->size_bytes);

        $decrypted = $vault->decryptToTemp(StoredObjectMapper::fromMediaFile($mediaFile));
        $stablePath = PipelineWorkspace::tempPath($this->mediaFileUuid);

        if (! @rename($decrypted->path, $stablePath)) {
            throw new RuntimeException("Could not stage decrypted file for [{$this->mediaFileUuid}].");
        }

        // $decrypted's own destructor (frozen TempFile, always unlinks on
        // GC) is now a safe no-op: is_file($decrypted->path) is false since
        // the file was just moved to $stablePath above. From here to
        // CleanupTemp, nothing holds a TempFile object for this file at all
        // — see PipelineWorkspace's docblock.
    }

    private function assertFreeSpace(int $sizeBytes): void
    {
        $root = Storage::disk('work')->path('');
        $free = @disk_free_space($root);

        // Mirrors EncryptedLocalVault::beginUpload's own 2.1x margin: the
        // plaintext this job is about to write sits alongside the
        // still-present encrypted original.
        if ($free === false || $free < $sizeBytes * 2.1) {
            throw new InsufficientStorage(
                "Not enough free disk space to decrypt [{$this->mediaFileUuid}] ({$sizeBytes} bytes) to [{$root}]."
            );
        }
    }
}
