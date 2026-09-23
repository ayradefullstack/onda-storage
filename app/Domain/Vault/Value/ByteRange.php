<?php

declare(strict_types=1);

namespace App\Domain\Vault\Value;

use InvalidArgumentException;

/**
 * An inclusive byte range [start, end], mirroring HTTP Range semantics.
 */
final readonly class ByteRange
{
    public function __construct(
        public int $start,
        public int $end,
    ) {
        if ($start < 0) {
            throw new InvalidArgumentException("Range start must be >= 0, got {$start}.");
        }

        if ($end < $start) {
            throw new InvalidArgumentException("Range end ({$end}) must be >= start ({$start}).");
        }
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    /**
     * Aligns the start down to the nearest block boundary, extending the
     * range so it still covers every originally-requested byte. The end is
     * left untouched — only the start needs to land on a cipher block
     * boundary for CTR decryption to begin correctly.
     */
    public function alignedDown(int $block = 16): self
    {
        $alignedStart = intdiv($this->start, $block) * $block;

        return new self($alignedStart, $this->end);
    }

    /**
     * Parses an HTTP `Range` header (`bytes=start-end`, `bytes=start-`, or
     * `bytes=-suffixLength`) against a known file size. Returns null for
     * anything absent, malformed, multi-range, or out of bounds — this
     * method must never throw on untrusted user input.
     */
    public static function fromHeader(?string $header, int $fileSize): ?self
    {
        if ($header === null || $fileSize <= 0) {
            return null;
        }

        $header = trim($header);

        if (! preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches)) {
            return null;
        }

        [, $startPart, $endPart] = $matches;

        if ($startPart === '' && $endPart === '') {
            return null;
        }

        if ($startPart === '') {
            $suffixLength = (int) $endPart;

            if ($suffixLength <= 0) {
                return null;
            }

            $start = max(0, $fileSize - $suffixLength);
            $end = $fileSize - 1;
        } else {
            $start = (int) $startPart;
            $end = $endPart === '' ? $fileSize - 1 : (int) $endPart;
        }

        if ($start < 0 || $end < $start || $start >= $fileSize) {
            return null;
        }

        $end = min($end, $fileSize - 1);

        try {
            return new self($start, $end);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
