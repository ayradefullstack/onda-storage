<?php

declare(strict_types=1);

namespace App\Enums\Language;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

/**
 * `languages.direction`. The key ('ltr' / 'rtl') is the HTML `dir` value,
 * which is what LanguageService and the shared `direction` prop hand out.
 */
enum DirectionEnum: int implements HasPresentation
{
    use EnumHelpers;

    case LTR = 1;
    case RTL = 2;

    public function label(): string
    {
        return __('enums.language.direction.'.$this->key());
    }

    public function color(): string
    {
        return 'neutral';
    }

    public function icon(): string
    {
        return match ($this) {
            self::LTR => 'pilcrow-right',
            self::RTL => 'pilcrow-left',
        };
    }
}
