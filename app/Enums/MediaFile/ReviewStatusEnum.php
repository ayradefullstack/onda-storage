<?php

declare(strict_types=1);

namespace App\Enums\MediaFile;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

/**
 * `media_files.review_status` — the officer's decision on one file, and
 * `media_file_reviews.from_status` / `to_status`.
 *
 * Separate from `media_files.status` on purpose: that column is the
 * technical pipeline (uploading … ready / failed / quarantined), this one
 * is a human decision. Written only by App\Actions\Review\DecideMediaFile.
 */
enum ReviewStatusEnum: int implements HasPresentation
{
    use EnumHelpers;

    case PENDING = 1;
    case ACCEPTED = 2;
    case REJECTED = 3;

    public function label(): string
    {
        return __('enums.media_file.review_status.'.$this->key());
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'neutral',
            self::ACCEPTED => 'success',
            self::REJECTED => 'destructive',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'clock',
            self::ACCEPTED => 'circle-check',
            self::REJECTED => 'circle-x',
        };
    }
}
