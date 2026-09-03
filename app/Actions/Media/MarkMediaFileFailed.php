<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\PipelineWorkspace;
use App\Models\MediaFile;
use App\Notifications\MediaFileFailedNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared by every pipeline job's `failed()` — `media_files` has no
 * dedicated failure-reason column (the model and its migration are frozen
 * this phase), so "records the reason" means the structured application
 * log, not a database column.
 */
final class MarkMediaFileFailed
{
    public function __construct(
        private readonly TransitionMediaFileStatus $transition,
    ) {}

    public function handle(string $mediaFileUuid, string $jobClass, Throwable $reason): void
    {
        $mediaFile = MediaFile::where('uuid', $mediaFileUuid)->first();

        if ($mediaFile === null) {
            Log::error('MarkMediaFileFailed: media file not found — nothing to mark.', [
                'media_file_uuid' => $mediaFileUuid,
                'job' => $jobClass,
                'reason' => $reason->getMessage(),
            ]);

            PipelineWorkspace::delete($mediaFileUuid);

            return;
        }

        Log::error('Media pipeline job failed permanently.', [
            'media_file_uuid' => $mediaFileUuid,
            'job' => $jobClass,
            'reason' => $reason->getMessage(),
        ]);

        $this->transition->handle($mediaFile, MediaFileStatus::FAILED);

        PipelineWorkspace::delete($mediaFileUuid);

        $mediaFile->uploadedBy->notify(new MediaFileFailedNotification($mediaFile));
    }
}
