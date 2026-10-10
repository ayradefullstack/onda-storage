<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Models\MediaFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only questions about whether the rows and the bytes agree. Nothing
 * here ever deletes or writes: it is the shared source for the pipeline's
 * "are these bytes really there" guard, `vault:doctor`, and
 * `vault:mark-missing-bytes`.
 */
final class VaultConsistency
{
    /** Files newer than this are skipped by the orphan scan (mid-upload). */
    private const ORPHAN_GRACE_SECONDS = 900;

    /**
     * The row's ciphertext and MAC sidecar exist and the ciphertext has the
     * expected length (AES-CTR is length preserving, so it equals
     * `size_bytes`).
     */
    public function bytesIntact(MediaFile $mediaFile): bool
    {
        $disk = Storage::disk($mediaFile->disk);
        $bin = $disk->path($mediaFile->path);

        return is_file($bin)
            && is_file($disk->path($mediaFile->mac_path))
            && filesize($bin) === $mediaFile->size_bytes;
    }

    /**
     * Non-purged rows (soft-deleted included) whose bin or mac is missing.
     *
     * @return Collection<int, array{file: MediaFile, bin: bool, mac: bool}>
     */
    public function missing(): Collection
    {
        $missing = collect();

        foreach (MediaFile::withTrashed()->whereNull('purged_at')->orderBy('id')->cursor() as $file) {
            $disk = Storage::disk($file->disk);
            $bin = is_file($disk->path($file->path));
            $mac = is_file($disk->path($file->mac_path));

            if (! $bin || ! $mac) {
                $missing->push(['file' => $file, 'bin' => $bin, 'mac' => $mac]);
            }
        }

        return $missing;
    }

    /**
     * Rows already marked failed whose bytes are (still) missing: resolved
     * by vault:mark-missing-bytes, but reported rather than hidden.
     *
     * @return Collection<int, array{file: MediaFile, bin: bool, mac: bool}>
     */
    public function markedMissing(): Collection
    {
        return $this->missing()->filter(fn (array $m): bool => $m['file']->status === 'failed')->values();
    }

    /**
     * Sets `ref_count` to the real number of non-purged rows sharing the
     * path, for every mismatched row. Returns how many rows changed.
     */
    public function recountRefs(): int
    {
        $changed = 0;

        foreach ($this->refCountMismatches() as $mismatch) {
            MediaFile::withTrashed()->where('id', $mismatch['file']->id)->update(['ref_count' => $mismatch['actual']]);
            $changed++;
        }

        return $changed;
    }

    /**
     * Rows whose `ref_count` differs from the number of non-purged rows
     * (trashed included) that share their path.
     *
     * @return Collection<int, array{file: MediaFile, actual: int}>
     */
    public function refCountMismatches(): Collection
    {
        $counts = MediaFile::withTrashed()->whereNull('purged_at')
            ->selectRaw('path, count(*) as n')->groupBy('path')->pluck('n', 'path');

        $mismatches = collect();

        foreach (MediaFile::withTrashed()->whereNull('purged_at')->orderBy('id')->cursor() as $file) {
            $actual = (int) ($counts[$file->path] ?? 0);

            if ($file->ref_count !== $actual) {
                $mismatches->push(['file' => $file, 'actual' => $actual]);
            }
        }

        return $mismatches;
    }

    /**
     * Vault files that no row references. Listed, never deleted.
     *
     * @return list<string>
     */
    public function orphans(): array
    {
        $referenced = [];

        foreach (MediaFile::withTrashed()->get(['path', 'mac_path']) as $row) {
            $referenced[$row->path] = true;
            $referenced[$row->mac_path] = true;
        }

        $disk = Storage::disk('vault');
        $orphans = [];

        foreach ($disk->allFiles() as $path) {
            // Only the sharded layout `xx/yy/<uuid>.bin|mac` — never the
            // `quarantine/` folder or anything else a human put there.
            if (! preg_match('~^[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f-]{36}\.(bin|mac)$~', $path) || isset($referenced[$path])) {
                continue;
            }

            if (time() - $disk->lastModified($path) < self::ORPHAN_GRACE_SECONDS) {
                continue;
            }

            $orphans[] = $path;
        }

        return $orphans;
    }
}
