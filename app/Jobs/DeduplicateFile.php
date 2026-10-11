<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Deposit\Value\StoredObjectMapper;
use App\Domain\Deposit\VaultBytesReleaser;
use App\Domain\Deposit\VaultConsistency;
use App\Domain\Vault\Value\StoredObject;
use App\Models\MediaFile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Links a duplicate upload's row to an existing file's bytes instead of
 * keeping a second copy on disk. This is the easiest place in the project
 * to destroy someone's legal deposit — read every line here twice.
 *
 * Principle: bytes are deleted ONLY when provably unreferenced; when in
 * doubt, keep them.
 *
 * The earlier version picked "any other row with this hash" as the original.
 * Two identical files hashed before either deduplicated each chose the other
 * as the original: the first repointed itself at the second's path and
 * deleted its own bytes, then the second "repointed" to the path it already
 * owned and deleted it — the last copy. Both rows ended up sharing a path
 * with no file behind it. The rules below make that impossible:
 *
 *  - Everything happens in ONE database transaction that holds
 *    `SELECT … FOR UPDATE` on every row with this hash, so two dedups can
 *    never decide on stale state. (The cache lock stays as an extra guard,
 *    with a TTL longer than the job timeout.)
 *  - The canonical copy is DETERMINISTIC: the lowest-id row whose own bytes
 *    verify on disk (bin + mac present, size matches). Whatever order the
 *    jobs run in, they all agree on it, and the canonical row itself does
 *    nothing.
 *  - If no row's bytes verify, nothing is deduplicated: both copies stay.
 *  - Bytes are released only AFTER the commit, and only through
 *    `VaultBytesReleaser`, which re-counts every row (trashed included) that
 *    still points at the path before deleting anything.
 *  - A row that vanished mid-pipeline ends the job quietly and touches no
 *    bytes.
 *
 * `ref_count` is RECOMPUTED here from the actual rows sharing the path (in
 * the same transaction). It is informational — no deletion decision reads it.
 */
final class DeduplicateFile extends PipelineJob
{
    public int $timeout = 300;

    public function handle(VaultBytesReleaser $releaser, VaultConsistency $consistency): void
    {
        $mediaFile = MediaFile::where('uuid', $this->mediaFileUuid)->first();

        if ($mediaFile === null) {
            // Deleted (or never existed, e.g. a reset database) while queued.
            Log::warning("DeduplicateFile: [{$this->mediaFileUuid}] no longer exists — nothing to do, no bytes touched.");

            return;
        }

        if ($mediaFile->sha256_plain === null) {
            throw new RuntimeException("Cannot deduplicate [{$this->mediaFileUuid}]: sha256_plain is not set (ComputeContentHash must run first).");
        }

        $ttl = $this->timeout * 2;

        $orphaned = Cache::lock("media-dedup:{$mediaFile->sha256_plain}", $ttl)
            ->block($ttl, fn (): ?StoredObject => $this->link($mediaFile, $consistency));

        if ($orphaned !== null) {
            // After the commit, never inside it.
            $releaser->releaseIfUnreferenced($mediaFile, $orphaned, 'dedup');
        }
    }

    /**
     * @return StoredObject|null this row's previous bytes, now to be released; null when nothing was relinked
     */
    private function link(MediaFile $mediaFile, VaultConsistency $consistency): ?StoredObject
    {
        return DB::transaction(function () use ($mediaFile, $consistency): ?StoredObject {
            /** @var Collection<int, MediaFile> $rows */
            $rows = MediaFile::withTrashed()
                ->where('sha256_plain', $mediaFile->sha256_plain)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $current = $rows->firstWhere('id', $mediaFile->id);

            if ($current === null || $current->trashed()) {
                return null;
            }

            $canonical = $rows->first(
                fn (MediaFile $row): bool => $row->purged_at === null
                    && ($row->id === $current->id || ! in_array($row->status, ['failed', 'quarantined'], true))
                    && $consistency->bytesIntact($row),
            );

            if ($canonical === null) {
                Log::warning("DeduplicateFile: no copy of [{$current->sha256_plain}] verified on disk — keeping every copy.");

                return null;
            }

            if ($canonical->id === $current->id || $this->samePath($canonical, $current)) {
                $this->syncRefCount($canonical->disk, $canonical->path);

                return null;
            }

            $orphaned = StoredObjectMapper::fromMediaFile($current);

            $current->disk = $canonical->disk;
            $current->path = $canonical->path;
            $current->mac_path = $canonical->mac_path;
            $current->dek_wrapped = $canonical->dek_wrapped;
            $current->nonce = $canonical->nonce;
            $current->save();

            $this->syncRefCount($canonical->disk, $canonical->path);

            return $orphaned;
        });
    }

    private function samePath(MediaFile $a, MediaFile $b): bool
    {
        return $a->disk === $b->disk && $a->path === $b->path;
    }

    private function syncRefCount(string $disk, string $path): void
    {
        $count = MediaFile::withTrashed()->where('disk', $disk)->where('path', $path)->whereNull('purged_at')->count();

        MediaFile::withTrashed()->where('disk', $disk)->where('path', $path)->update(['ref_count' => $count]);
    }
}
