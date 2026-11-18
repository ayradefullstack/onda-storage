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
            ? "Only a ready file can be accepted; this file is \"{$status->key()}\"."
            : "This file is still \"{$status->key()}\"; it can be decided once the pipeline has finished with it.");
    }

    public static function removed(): self
    {
        return new self('This file was removed by the author; it can no longer be decided.');
    }

    public static function foreignFile(): self
    {
        return new self('This file does not belong to this deposit.');
    }
}
