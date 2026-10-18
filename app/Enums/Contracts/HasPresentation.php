<?php

declare(strict_types=1);

namespace App\Enums\Contracts;

use JsonSerializable;

/**
 * What every application enum (`App\Enums\**`) can say about one of its
 * cases for display: a translated label, a semantic badge tone (one of the
 * UI's StatusTone names — success, warning, destructive, info, neutral,
 * primary) and a lucide icon name.
 *
 * It extends JsonSerializable on purpose: the database stores the int, but
 * an enum leaves the backend as its KEY ('submitted', 'active', 'rtl') —
 * in Inertia props, JSON responses and notification payloads alike. The
 * implementation is EnumHelpers::jsonSerialize().
 */
interface HasPresentation extends JsonSerializable
{
    public function label(): string;

    public function color(): string;

    public function icon(): string;
}
