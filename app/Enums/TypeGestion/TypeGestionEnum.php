<?php

declare(strict_types=1);

namespace App\Enums\TypeGestion;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

/**
 * The "type de gestion" code ONDA stores: `type_gestions.type_gestion` and
 * `register_type_colleges.type_gestion` hold the same value (the seeder
 * matches colleges on it), so one class serves both columns. The numbers
 * are ONDA's own and predate this enum.
 */
enum TypeGestionEnum: int implements HasPresentation
{
    use EnumHelpers;

    case COLLECTIVE = 1;
    case INDIVIDUAL = 2;
    case SIMPLE = 3;

    public function label(): string
    {
        return __('enums.type_gestion.type_gestion.'.$this->key());
    }

    public function color(): string
    {
        return match ($this) {
            self::COLLECTIVE => 'primary',
            self::INDIVIDUAL => 'info',
            self::SIMPLE => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::COLLECTIVE => 'users',
            self::INDIVIDUAL => 'user',
            self::SIMPLE => 'shield',
        };
    }
}
