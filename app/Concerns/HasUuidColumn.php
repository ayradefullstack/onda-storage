<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Support\Str;

/**
 * Assigns a UUIDv7 to the model's `uuid` column on creation and makes it the
 * route key, so sequential ids are never exposed in URLs.
 *
 * `Str::uuid7()` exists on this Laravel version (13.26 / framework ^13.17),
 * so it is used directly rather than falling back to `Str::orderedUuid()`
 * (which produces a UUIDv4 with a time-ordered prefix, not a real UUIDv7).
 */
trait HasUuidColumn
{
    public static function bootHasUuidColumn(): void
    {
        static::creating(function ($model): void {
            if (! $model->uuid) {
                $model->uuid = (string) Str::uuid7();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
