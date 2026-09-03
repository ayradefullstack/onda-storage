<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Value;

/**
 * `media_variants` (frozen this phase) has no `nonce` column, but AES-256-CTR
 * is unsafe to reuse a (key, nonce) pair across two different plaintexts —
 * and a variant encrypted with the file's own DEK MUST NOT reuse the file's
 * own nonce, since the variant's bytes are different plaintext under the
 * same key. This derives a nonce deterministically from the file's own
 * 8-byte nonce plus the variant kind, so it never needs its own storage:
 * unique per (file, kind) pair, reproducible by anything that later needs
 * to decrypt a variant (a later phase's read path) without a new column.
 */
final class VariantEncryption
{
    public static function nonceFor(string $fileNonceHex, string $variantKind): string
    {
        return substr(hash('sha256', $fileNonceHex.':'.$variantKind, true), 0, 8);
    }
}
