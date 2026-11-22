<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use RuntimeException;

/**
 * The file cannot take this decision as it stands: accepting needs a
 * `ready` file, rejecting or resetting needs one the pipeline has finished
 * with (ready, failed or quarantined). A 422 with this exact message.
 */
final class FileDecisionRefused extends RuntimeException
{
    public static function fileStatus(MediaFileStatus $status, ReviewStatusEnum $to): self
    {
        return new self($to === ReviewStatusEnum::ACCEPTED
            ? __('review.file_accept_needs_ready', ['status' => $status->label()])
            : __('review.file_decide_needs_finished', ['status' => $status->label()]));
    }

    public static function removed(): self
    {
        return new self(__('review.file_removed'));
    }

    public static function foreignFile(): self
    {
        return new self(__('review.file_foreign'));
    }
}
