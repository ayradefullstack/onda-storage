<?php

declare(strict_types=1);

namespace App\Domain\Vault\Value;

/**
 * Identifies a single chunk within an upload by its absolute byte position.
 */
final readonly class ChunkRef
{
    public function __construct(
        public string $uploadUuid,
        public int $index,
        public int $offset,
        public int $length,
    ) {}

    public function byteRange(): ByteRange
    {
        return new ByteRange($this->offset, $this->offset + $this->length - 1);
    }
}
