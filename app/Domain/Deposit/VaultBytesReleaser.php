<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Access\AccessLogger;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Value\StoredObject;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Log;

/**
 * THE one place vault bytes are deleted.
 *
 * Principle: bytes are deleted ONLY when provably unreferenced. When in
 * doubt, keep them — an orphan file is acceptable, a destroyed deposit is
 * not. The decision is made by re-counting, at the moment of deletion, every
 * `media_files` row (soft-deleted included, because a trashed row still owns
 * its bytes until purge) that points at the path. `ref_count` is never used
 * for this decision.
 *
 * Callers must invoke it AFTER their own transaction has committed — the
 * count has to see the repointed rows — and never from inside one.
 *
 * Every decision is written to the hash-chained ledger (`bytes_released` /
 * `bytes_kept`, against `$context`, the row that triggered it) and to the
 * application log with the path and the count. An architecture test forbids
 * calling `VaultContract::destroy()` from anywhere else.
 */
final class VaultBytesReleaser
{
    public function __construct(private readonly VaultContract $vault) {}

    /**
     * How many rows still claim these bytes. The single definition of
     * "referenced", shared by the release decision and by every command that
     * moves vault files (vault:quarantine-orphans re-checks it per file).
     */
    public function referenceCount(string $disk, string $path, ?string $macPath = null): int
    {
        return MediaFile::withTrashed()
            ->whereNull('purged_at')
            ->where('disk', $disk)
            ->where(function ($query) use ($path, $macPath): void {
                $query->where('path', $path)->orWhere('mac_path', $path);

                if ($macPath !== null) {
                    $query->orWhere('path', $macPath)->orWhere('mac_path', $macPath);
                }
            })
            ->count();
    }

    /**
     * @return bool true when the bytes were deleted
     */
    public function releaseIfUnreferenced(MediaFile $context, StoredObject $object, string $reason): bool
    {
        // A purged row (`purged_at` set) has given its claim up: it is the
        // purge job's own way of saying "release my bytes".
        $references = $this->referenceCount($object->disk, $object->path, $object->macPath);

        $delete = $references === 0;

        if ($delete) {
            $this->vault->destroy($object);
        }

        Log::info($delete ? 'Vault bytes released.' : 'Vault bytes kept.', [
            'path' => $object->path,
            'referencing_rows' => $references,
            'deleted' => $delete,
            'reason' => $reason,
            'context_media_file' => $context->uuid,
        ]);

        AccessLogger::record($context, null, $delete ? 'bytes_released' : 'bytes_kept', 'system', null);

        return $delete;
    }
}
