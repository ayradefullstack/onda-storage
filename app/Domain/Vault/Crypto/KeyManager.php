<?php

declare(strict_types=1);

namespace App\Domain\Vault\Crypto;

use App\Domain\Vault\Exceptions\InvalidMasterKey;
use App\Domain\Vault\Exceptions\MacVerificationFailed;

/**
 * Owns the master KEK and every per-file DEK wrap/unwrap operation.
 *
 * The KEK is cached only in a private instance property for the lifetime of
 * this object (effectively the request) — never in Laravel's cache, never in
 * a static property, never logged, and never interpolated into an exception
 * message.
 *
 * Wrapping reuses the same encrypt-then-MAC construction as the main file
 * cipher (AES-256-CTR + HMAC-SHA256, both keys HKDF-derived from the KEK)
 * rather than introducing a second primitive (e.g. GCM) into the codebase.
 */
final class KeyManager
{
    /** Bytes of the random per-wrap nonce, fresh on every wrap() call. */
    public const NONCE_LENGTH = 8;

    /** Bytes of the HMAC-SHA256 tag. */
    public const TAG_LENGTH = 32;

    /** Bytes of a DEK, as produced by generateDek(). */
    public const DEK_LENGTH = 32;

    /** Raw (pre-base64) length of a wrapped DEK: nonce . tag . ciphertext. */
    public const WRAPPED_LENGTH = self::NONCE_LENGTH + self::TAG_LENGTH + self::DEK_LENGTH;

    private ?string $kek = null;

    public function __construct(
        private readonly CtrCipher $cipher = new CtrCipher,
    ) {}

    public function generateDek(): string
    {
        return random_bytes(self::DEK_LENGTH);
    }

    public function generateNonce(): string
    {
        return random_bytes(self::NONCE_LENGTH);
    }

    public function wrap(string $dek): string
    {
        $kek = $this->kek();
        $nonce = $this->generateNonce();

        $encKey = HkdfKeys::encryptionKey($kek);
        $macKey = HkdfKeys::macKey($kek);

        $ciphertext = $this->cipher->transformAt($dek, $encKey, $nonce, 0);
        $tag = hash_hmac('sha256', $nonce.$ciphertext, $macKey, true);

        return base64_encode($nonce.$tag.$ciphertext);
    }

    public function unwrap(string $wrapped): string
    {
        $kek = $this->kek();
        $raw = base64_decode($wrapped, true);

        if ($raw === false || strlen($raw) < self::NONCE_LENGTH + self::TAG_LENGTH) {
            throw new MacVerificationFailed('Wrapped key material is malformed.');
        }

        $nonce = substr($raw, 0, self::NONCE_LENGTH);
        $tag = substr($raw, self::NONCE_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::NONCE_LENGTH + self::TAG_LENGTH);

        $encKey = HkdfKeys::encryptionKey($kek);
        $macKey = HkdfKeys::macKey($kek);

        $expectedTag = hash_hmac('sha256', $nonce.$ciphertext, $macKey, true);

        if (! hash_equals($expectedTag, $tag)) {
            throw new MacVerificationFailed('Wrapped key MAC verification failed — the DEK envelope may be corrupted or tampered with.');
        }

        return $this->cipher->transformAt($ciphertext, $encKey, $nonce, 0);
    }

    private function kek(): string
    {
        if ($this->kek !== null) {
            return $this->kek;
        }

        $path = config('vault.master_key_path');

        if (! is_string($path) || $path === '') {
            throw new InvalidMasterKey(
                'VAULT_MASTER_KEY_PATH is not configured. Point it at a 32+ byte random key file outside the project directory.'
            );
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_file($resolved)) {
            throw new InvalidMasterKey(
                "Master key file not found at [{$path}]. Generate one with random_bytes(32) and write it there."
            );
        }

        if (! is_readable($resolved)) {
            throw new InvalidMasterKey(
                "Master key file at [{$path}] is not readable by the current process user."
            );
        }

        $normalizedResolved = str_replace('\\', '/', $resolved);
        $normalizedBase = rtrim(str_replace('\\', '/', base_path()), '/');

        if ($normalizedResolved === $normalizedBase || str_starts_with($normalizedResolved, $normalizedBase.'/')) {
            throw new InvalidMasterKey(
                'The master key must not live inside the project directory — move it outside the repo and outside every backup set.'
            );
        }

        $contents = file_get_contents($resolved);

        if ($contents === false) {
            throw new InvalidMasterKey("Could not read the master key file at [{$path}].");
        }

        if (strlen($contents) < 32) {
            throw new InvalidMasterKey('The master key file must be at least 32 bytes.');
        }

        return $this->kek = $contents;
    }
}
