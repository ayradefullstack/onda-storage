<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Contracts\HasPresentation;

/**
 * `toArray()` / `toJson()` / Inertia props emit an enum column as its KEY
 * ('submitted'), not its stored int.
 *
 * Laravel's own array cast turns an enum attribute into its backing value
 * before anything is JSON-encoded, so the enum's jsonSerialize() never runs
 * on that path; this puts the key back for every attribute cast to an
 * application enum (HasPresentation). Database writes are untouched — they
 * still store the int.
 */
trait SerializesEnumsAsKeys
{
    /**
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        foreach ($this->getCasts() as $key => $cast) {
            if (! array_key_exists($key, $attributes) || $attributes[$key] === null) {
                continue;
            }

            if (is_string($cast) && is_subclass_of($cast, HasPresentation::class)) {
                $value = $this->getAttribute($key);

                if ($value instanceof HasPresentation && $value instanceof \BackedEnum) {
                    $attributes[$key] = $value->jsonSerialize();
                }
            }
        }

        return $attributes;
    }
}
