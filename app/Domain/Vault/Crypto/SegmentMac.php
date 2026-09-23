<?php

declare(strict_types=1);

namespace App\Domain\Vault\Crypto;

use App\Domain\Vault\Exceptions\MacVerificationFailed;

/**
 * CTR is unauthenticated, so ciphertext is HMAC'd per fixed-size segment
 * (encrypt-then-MAC). Tags are stored concatenated in a sidecar `{uuid}.mac`
 * file: tag N lives at byte offset N*32, so any segment's tag is seekable
 * without reading the rest of the file.
 */
final class SegmentMac
{
    private const TAG_SIZE = 32;

    private readonly int $segmentSize;

    public function __construct(?int $segmentSize = null)
    {
        $this->segmentSize = $segmentSize ?? (int) config('vault.mac_segment_size', 1_048_576);
    }

    public function segmentSize(): int
    {
        return $this->segmentSize;
    }

    public function segmentIndexFor(int $byteOffset): int
    {
        return intdiv($byteOffset, $this->segmentSize);
    }

    /**
     * Raw 32-byte HMAC-SHA256 over fileUuid . segIndex(uint64 BE) . ciphertext.
     */
    public function tagFor(string $macKey, string $fileUuid, int $segIndex, string $ciphertext): string
    {
        return hash_hmac('sha256', $fileUuid.pack('J', $segIndex).$ciphertext, $macKey, true);
    }

    /**
     * @param  resource  $macHandle
     */
    public function writeTag($macHandle, int $segIndex, string $tag): void
    {
        fseek($macHandle, $segIndex * self::TAG_SIZE);
        fwrite($macHandle, $tag);
    }

    /**
     * @param  resource  $macHandle
     */
    public function readTag($macHandle, int $segIndex): ?string
    {
        if (fseek($macHandle, $segIndex * self::TAG_SIZE) !== 0) {
            return null;
        }

        $tag = fread($macHandle, self::TAG_SIZE);

        if ($tag === false || strlen($tag) !== self::TAG_SIZE) {
            return null;
        }

        return $tag;
    }

    /**
     * Verifies every segment covering [start, end] by re-reading only those
     * segments' ciphertext and tags — never the whole file. Throws on the
     * first mismatch, missing tag, or unreadable segment.
     *
     * @param  resource  $ciphertextHandle
     * @param  resource  $macHandle
     */
    public function verifyRange($ciphertextHandle, $macHandle, string $macKey, string $fileUuid, int $start, int $end): void
    {
        $firstSeg = $this->segmentIndexFor($start);
        $lastSeg = $this->segmentIndexFor($end);

        for ($seg = $firstSeg; $seg <= $lastSeg; $seg++) {
            $segStart = $seg * $this->segmentSize;

            if (fseek($ciphertextHandle, $segStart) !== 0) {
                throw new MacVerificationFailed("Could not seek to segment {$seg} for MAC verification.");
            }

            $ciphertext = fread($ciphertextHandle, max(1, $this->segmentSize));

            if ($ciphertext === false || $ciphertext === '') {
                throw new MacVerificationFailed("Could not read segment {$seg} for MAC verification.");
            }

            $expected = $this->readTag($macHandle, $seg);

            if ($expected === null) {
                throw new MacVerificationFailed("MAC tag for segment {$seg} is missing or truncated.");
            }

            $actual = $this->tagFor($macKey, $fileUuid, $seg, $ciphertext);

            if (! hash_equals($expected, $actual)) {
                throw new MacVerificationFailed("MAC verification failed for segment {$seg}.");
            }
        }
    }
}
