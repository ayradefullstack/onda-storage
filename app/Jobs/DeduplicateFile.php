<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Deposit\Value\StoredObjectMapper;
use App\Domain\Vault\Contracts\VaultContract;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Links a duplicate upload's row to an existing file's bytes instead of
 * keeping a second copy on disk. This is the easiest place in the project
 * to destroy someone's legal deposit — read every line here twice.
 *
 * Only ever deletes THIS row's own, just-uploaded bytes (captured into
 * `$orphanedObject` before the row is repointed) — never the original's.
 * Those bytes are uniquely named per upload (see
 * `EncryptedLocalVault::finalize()`'s uuid-sharded path), so nothing else
 * can be referencing them yet; deleting them is unconditionally safe at
 * this exact point, before this row is repointed to share the original's
 * path.
 */
final class DeduplicateFile extends PipelineJob
{
    public int $timeout = 300;

    public function handle(VaultContract $vault): void
    {
        $mediaFile = MediaFile::where('uuid', $this->mediaFileUuid)->firstOrFail();

        if ($mediaFile->sha256_plain === null) {
            throw new RuntimeException("Cannot deduplicate [{$this->mediaFileUuid}]: sha256_plain is not set (ComputeContentHash must run first).");
        }

        $lock = Cache::lock("media-dedup:{$mediaFile->sha256_plain}", (int) config('vault.dedup_lock_seconds'));

        $lock->block(10, function () use ($mediaFile, $vault): void {
            $original = $this->findOriginal($mediaFile);

            if ($original === null) {
                return; // This file IS the original — nothing to link.
            }

            $orphanedObject = StoredObjectMapper::fromMediaFile($mediaFile);

            $mediaFile->disk = $original->disk;
            $mediaFile->path = $original->path;
            $mediaFile->mac_path = $original->mac_path;
            $mediaFile->dek_wrapped = $original->dek_wrapped;
            $mediaFile->nonce = $original->nonce;
            $mediaFile->save();

            $original->increment('ref_count');

            $vault->destroy($orphanedObject);
        });
    }

    private function findOriginal(MediaFile $mediaFile): ?MediaFile
    {
        return MediaFile::withTrashed()
            ->where('sha256_plain', $mediaFile->sha256_plain)
            ->where('id', '!=', $mediaFile->id)
            ->whereNull('purged_at')
            // A soft-deleted-but-not-purged row's bytes are still on disk
            // (CLAUDE.md: quota counts withTrashed()->whereNull('purged_at'))
            // and are a valid dedup target. 'failed'/'quarantined' rows are
            // excluded: their bytes may already be in an inconsistent state
            // (moved to quarantine, or never finished being written), and a
            // quarantine verdict must never be inherited implicitly — this
            // upload gets its own independent scan either way.
            ->whereNotIn('status', ['failed', 'quarantined'])
            ->orderBy('id')
            ->first();
    }
}
