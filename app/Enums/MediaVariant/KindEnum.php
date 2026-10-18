<?php

declare(strict_types=1);

namespace App\Enums\MediaVariant;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

/**
 * `media_variants.kind`. The admin variant route keeps the KEY in its URL
 * (`/…/variants/poster`), resolved with fromKey(); an unknown key is a 404.
 */
enum KindEnum: int implements HasPresentation
{
    use EnumHelpers;

    case POSTER = 1;
    case PREVIEW = 2;
    case WAVEFORM = 3;
    case THUMBNAIL = 4;

    public function label(): string
    {
        return __('enums.media_variant.kind.'.$this->key());
    }

    public function color(): string
    {
        return 'neutral';
    }

    public function icon(): string
    {
        return match ($this) {
            self::POSTER => 'image',
            self::PREVIEW => 'film',
            self::WAVEFORM => 'audio-waveform',
            self::THUMBNAIL => 'image-down',
        };
    }
}
