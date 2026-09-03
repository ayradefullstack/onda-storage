<?php

declare(strict_types=1);

namespace App\Domain\Vault\Crypto;

use App\Domain\Vault\Exceptions\UnalignedOffset;
use RuntimeException;

/**
 * AES-256-CTR: length-preserving and seekable, which is exactly why it's the
 * only cipher used for vault bytes — never substitute GCM or CBC here (see
 * CLAUDE.md). CTR is symmetric, so the same transform both encrypts and
 * decrypts.
 */
final class CtrCipher
{
    private const CIPHER = 'aes-256-ctr';

    private const BLOCK = 16;

    private const STREAM_BLOCK = 65536; // 64 KiB, a multiple of 16.

    public function ivFor(string $nonce8, int $byteOffset): string
    {
        return $nonce8.pack('J', intdiv($byteOffset, self::BLOCK));
    }

    /**
     * Encrypts or decrypts $data as if it were the bytes of a single
     * continuous CTR keystream starting at absolute offset 0, restarting the
     * counter exactly where $offset falls. $offset must be block-aligned —
     * otherwise the recomputed counter would not match the keystream
     * position the rest of the file was encrypted against.
     */
    public function transformAt(string $data, string $encKey, string $nonce, int $offset): string
    {
        if ($offset % self::BLOCK !== 0) {
            throw new UnalignedOffset("Offset {$offset} is not a multiple of ".self::BLOCK.'.');
        }

        if ($data === '') {
            return '';
        }

        $iv = $this->ivFor($nonce, $offset);
        $result = openssl_encrypt($data, self::CIPHER, $encKey, OPENSSL_RAW_DATA, $iv);

        if ($result === false) {
            throw new RuntimeException('AES-256-CTR transform failed: '.(openssl_error_string() ?: 'unknown error'));
        }

        return $result;
    }

    /**
     * Streams $in to $out in 64 KiB blocks, transforming as it goes, so a
     * multi-gigabyte file never enters memory whole. Returns bytes
     * processed.
     *
     * @param  resource  $in
     * @param  resource  $out
     */
    public function streamTransform($in, $out, string $encKey, string $nonce, int $startOffset, ?int $length = null): int
    {
        if ($startOffset % self::BLOCK !== 0) {
            throw new UnalignedOffset("Start offset {$startOffset} is not a multiple of ".self::BLOCK.'.');
        }

        $offset = $startOffset;
        $processed = 0;

        while ($length === null || $processed < $length) {
            $toRead = self::STREAM_BLOCK;

            if ($length !== null) {
                $toRead = min($toRead, $length - $processed);
            }

            if ($toRead <= 0) {
                break;
            }

            $chunk = fread($in, $toRead);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $transformed = $this->transformAt($chunk, $encKey, $nonce, $offset);
            fwrite($out, $transformed);

            $read = strlen($chunk);
            $offset += $read;
            $processed += $read;
        }

        return $processed;
    }
}
