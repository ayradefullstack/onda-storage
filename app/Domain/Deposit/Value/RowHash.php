<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Value;

/**
 * `row_hash = sha256(prev_hash . canonical_json_of_this_row)` — the same
 * formula used both to WRITE a new `file_access_logs` row (RecordDeposit)
 * and to VERIFY the chain later (tests, and any future audit tooling).
 * Chaining each row's hash into the next makes tampering with an old row
 * detectable: changing it changes its own row_hash, which no longer matches
 * what the next row's hash was computed against.
 */
final class RowHash
{
    /**
     * Deterministic JSON: key-sorted so the same payload always canonicalizes
     * to the same bytes regardless of array construction order.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function canonicalize(array $payload): string
    {
        ksort($payload);

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function compute(?string $prevHash, array $payload): string
    {
        return hash('sha256', ($prevHash ?? '').self::canonicalize($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function verify(?string $prevHash, array $payload, string $expectedRowHash): bool
    {
        return hash_equals(self::compute($prevHash, $payload), $expectedRowHash);
    }
}
