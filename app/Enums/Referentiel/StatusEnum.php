<?php

declare(strict_types=1);

namespace App\Enums\Referentiel;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

/**
 * `status` on the five classification reference tables (register_types,
 * type_gestions, register_type_colleges, register_type_members,
 * register_role_auteurs) — one shared class (app/Enums/<Domain>/ rule).
 *
 * INACTIVE is 2, not the 0 these columns held before: enum values start at
 * 1. HASH CHAIN: `reference_data_changes` hashes a changed `status` as this
 * permanent integer (1 / 2), not as its key.
 *
 * Distinct from `is_disabled` on the same tables (an existing overlap, kept).
 */
enum StatusEnum: int implements HasPresentation
{
    use EnumHelpers;

    case ACTIVE = 1;
    case INACTIVE = 2;

    public function label(): string
    {
        return __('enums.referentiel.status.'.$this->key());
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::INACTIVE => 'neutral',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ACTIVE => 'circle-check',
            self::INACTIVE => 'circle-pause',
        };
    }
}
