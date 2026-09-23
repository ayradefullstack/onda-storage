<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Deposit\Exceptions\RemovalRefused;
use App\Models\MediaFile;
use App\Models\Oeuvre;

/**
 * When a file may be taken off a deposit, and when a whole deposit may be
 * deleted by its author.
 *
 * Removal exists because the submission gate refuses a `failed` or
 * `quarantined` file: without a way to take one off, a single failed
 * upload would block submission permanently. That is why it is required
 * rather than a convenience.
 *
 * ---------------------------------------------------------------------
 * The mid-pipeline rule
 * ---------------------------------------------------------------------
 * A file in `scanning` or `processing` cannot be removed. The `Bus::chain()`
 * running over it holds that row's id and will keep hashing, scanning and
 * transcoding against a record the author has just trashed — jobs that
 * then write status and variant rows onto a deleted file, or fail in ways
 * that look like vault corruption. The author waits for the chain to
 * finish, which is seconds to minutes, and then removes it.
 *
 * `uploading` is not removable either, but for a different reason: it has
 * its own operation. An upload still in flight is cancelled through
 * AbortUpload, which also releases the pre-allocated bytes; going through
 * removal instead would leave the temp file behind.
 *
 * ---------------------------------------------------------------------
 * What removal does NOT do
 * ---------------------------------------------------------------------
 * It soft-deletes the row. It never touches the ciphertext or the `.mac`
 * sidecar — see CLAUDE.md: the purge job frees bytes after the retention
 * window and respects `ref_count`, because a deduplicated file shares its
 * bytes with another row and destroying them would empty that row too.
 * Storage quota therefore does not recover at removal time, and the
 * confirmation dialog says so.
 */
final class RemovalGate
{
    /**
     * Statuses whose pipeline is still running over the row.
     *
     * @var list<string>
     */
    private const MID_PIPELINE = [
        MediaFileStatus::ASSEMBLING,
        MediaFileStatus::SCANNING,
        MediaFileStatus::PROCESSING,
    ];

    /**
     * Removable statuses: the pipeline is done with the row, one way or
     * another.
     *
     * @var list<string>
     */
    private const REMOVABLE = [
        MediaFileStatus::READY,
        MediaFileStatus::FAILED,
        MediaFileStatus::QUARANTINED,
    ];

    /**
     * @throws RemovalRefused
     */
    public function assertFileRemovable(MediaFile $mediaFile): void
    {
        if ($mediaFile->status === MediaFileStatus::UPLOADING) {
            throw RemovalRefused::stillUploading($mediaFile->original_name);
        }

        if (in_array($mediaFile->status, self::MID_PIPELINE, true)) {
            throw RemovalRefused::midPipeline($mediaFile->original_name, $mediaFile->status);
        }

        if (! in_array($mediaFile->status, self::REMOVABLE, true)) {
            throw RemovalRefused::midPipeline($mediaFile->original_name, $mediaFile->status);
        }
    }

    /**
     * Deleting a whole oeuvre deletes its files, so it is subject to the
     * same mid-pipeline rule: one file still being scanned blocks the
     * delete, and the author is told which.
     *
     * @throws RemovalRefused
     */
    public function assertOeuvreDeletable(Oeuvre $oeuvre): void
    {
        $busy = $oeuvre->mediaFiles()
            ->whereIn('status', [MediaFileStatus::UPLOADING, ...self::MID_PIPELINE])
            ->first(['original_name', 'status']);

        if ($busy !== null) {
            throw RemovalRefused::midPipeline($busy->original_name, $busy->status);
        }
    }

    public function isFileRemovable(MediaFile $mediaFile): bool
    {
        return in_array($mediaFile->status, self::REMOVABLE, true);
    }
}
