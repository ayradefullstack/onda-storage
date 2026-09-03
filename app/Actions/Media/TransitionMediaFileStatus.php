<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Events\MediaFileStatusChanged;
use App\Models\MediaFile;

/**
 * The one place `media_files.status` is written from the pipeline — every
 * transition fires `MediaFileStatusChanged`, so nothing forgets to.
 */
final class TransitionMediaFileStatus
{
    public function handle(MediaFile $mediaFile, string $newStatus): void
    {
        $previousStatus = $mediaFile->status;

        if ($previousStatus === $newStatus) {
            return;
        }

        $mediaFile->status = $newStatus;
        $mediaFile->save();

        MediaFileStatusChanged::dispatch($mediaFile->uuid, $previousStatus, $newStatus);
    }
}
