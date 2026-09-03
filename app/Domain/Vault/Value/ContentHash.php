<?php

declare(strict_types=1);

namespace App\Domain\Vault\Value;

use InvalidArgumentException;

/**
 * A sha256 content digest, stored as lowercase hex — the same representation
 * as `media_files.sha256_plain`.
 */
final readonly class ContentHash
{
    private function __construct(
        public string $hex,
    ) {}

    public static function fromBinary(string $bytes): self
    {
        return new self(hash('sha256', $bytes));
    }

    public static function fromHex(string $hex): self
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $hex)) {
            throw new InvalidArgumentException('A sha256 hex digest must be 64 lowercase hex characters.');
        }

        return new self($hex);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->hex, $other->hex);
    }

    public function __toString(): string
    {
        return $this->hex;
    }
}
