<?php

declare(strict_types=1);

namespace App\Enums\MediaFile;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

/**
 * `media_files.status` — the post-upload pipeline. Moved only through
 * TransitionMediaFileStatus (and MarkMediaFileFailed for the failure path).
 */
enum StatusEnum: int implements HasPresentation
{
    use EnumHelpers;

    case UPLOADING = 1;
    case ASSEMBLING = 2;
    case SCANNING = 3;
    case PROCESSING = 4;
    case READY = 5;
    case FAILED = 6;
    case QUARANTINED = 7;

    public function label(): string
    {
        return __('enums.media_file.status.'.$this->key());
    }

    public function color(): string
    {
        return match ($this) {
            self::UPLOADING, self::ASSEMBLING, self::SCANNING, self::PROCESSING => 'info',
            self::READY => 'success',
            self::FAILED => 'destructive',
            self::QUARANTINED => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::UPLOADING => 'upload',
            self::ASSEMBLING => 'layers',
            self::SCANNING => 'shield-check',
            self::PROCESSING => 'loader',
            self::READY => 'circle-check',
            self::FAILED => 'circle-x',
            self::QUARANTINED => 'shield-alert',
        };
    }

    /**
     * A file that will never become `ready` without an author action.
     *
     * @return list<self>
     */
    public static function blocking(): array
    {
        return [self::FAILED, self::QUARANTINED];
    }
}
