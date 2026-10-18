<?php

declare(strict_types=1);

namespace App\Enums\Concerns;

/**
 * Shared behaviour of the int-backed application enums.
 *
 * - The VALUE (int) is what the database stores. It is permanent: never
 *   renumbered, never reused (a migration's column comment and every stored
 *   row depend on it).
 * - The KEY (the lowercase case name, `UNDER_REVIEW` → `under_review`) is
 *   what leaves the backend: JSON, Inertia props, URLs and filters. Lookups
 *   from the outside go through fromKey(), never through a translated label.
 *
 * @phpstan-require-implements \BackedEnum
 */
trait EnumHelpers
{
    public function key(): string
    {
        return strtolower($this->name);
    }

    /** The case whose key is `$key`; null for null, '' or an unknown key. */
    public static function fromKey(?string $key): ?static
    {
        if ($key === null || $key === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($case->key() === $key) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<int> */
    public static function values(): array
    {
        return array_map(fn (self $case): int => $case->value, self::cases());
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(fn (self $case): string => $case->key(), self::cases());
    }

    /** @return list<array{value: int, key: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'key' => $case->key(),
            'label' => $case->label(),
        ], self::cases());
    }

    /** The column comment: `App\Enums\Oeuvre\StatusEnum: 1=draft, 2=submitted, …`. */
    public static function dbComment(): string
    {
        $pairs = array_map(fn (self $case): string => "{$case->value}={$case->key()}", self::cases());

        return self::class.': '.implode(', ', $pairs);
    }

    public function jsonSerialize(): string
    {
        return $this->key();
    }
}
