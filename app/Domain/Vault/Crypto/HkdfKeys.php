<?php

declare(strict_types=1);

namespace App\Domain\Vault\Crypto;

/**
 * Derives separate encryption and authentication keys from a single DEK (or
 * KEK). The DEK itself is never used directly for either purpose — HKDF
 * domain-separates the two uses via the info parameter.
 */
final class HkdfKeys
{
    public static function encryptionKey(string $ikm): string
    {
        return hash_hkdf('sha256', $ikm, 32, 'onda-enc');
    }

    public static function macKey(string $ikm): string
    {
        return hash_hkdf('sha256', $ikm, 32, 'onda-mac');
    }
}
