<?php

declare(strict_types=1);

namespace App\Enums\MediaConsultation;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

/**
 * `media_consultations.status` — the derivative pipeline's state for one
 * file. Also the outcome a renderer reports (RenderResult).
 */
enum StatusEnum: int implements HasPresentation
{
    use EnumHelpers;

    case PENDING = 1;
    case READY = 2;
    case FAILED = 3;
    case UNSUPPORTED = 4;

    public function label(): string
    {
        return __('enums.media_consultation.status.'.$this->key());
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'info',
            self::READY => 'success',
            self::FAILED => 'destructive',
            self::UNSUPPORTED => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PENDING => 'loader',
            self::READY => 'circle-check',
            self::FAILED => 'circle-x',
            self::UNSUPPORTED => 'eye-off',
        };
    }
}
