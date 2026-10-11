<?php

declare(strict_types=1);

namespace App\Actions\Consultation;

use App\Domain\Vault\Crypto\CtrCipher;
use App\Domain\Vault\Crypto\HkdfKeys;
use App\Domain\Vault\Crypto\KeyManager;
use App\Domain\Vault\Value\ByteRange;
use App\Models\ConsultationAsset;
use App\Models\MediaFile;
use Generator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Yields the plaintext of a derivative (or a byte range of it) in <= 64 KiB
 * pieces. Only the requested ciphertext slice is read — native fseek on the
 * `variants` disk's stream, aligned down to the 16-byte cipher block — and
 * decrypted with `CtrCipher::transformAt`. A derivative is NEVER loaded whole
 * (`Storage::get`), because a full-length video is hundreds of MB.
 */
final class ReadConsultationAsset
{
    private const BLOCK = 65536; // multiple of 16

    public function __construct(
        private readonly KeyManager $keys,
        private readonly CtrCipher $cipher,
    ) {}

    /**
     * @return Generator<int, string>
     */
    public function stream(MediaFile $mediaFile, ConsultationAsset $asset, ?ByteRange $range): Generator
    {
        $start = $range !== null ? $range->start : 0;
        $length = $range?->length() ?? $asset->size_bytes;

        $nonce = hex2bin($asset->nonce);

        if ($nonce === false) {
            throw new RuntimeException("Malformed nonce for asset [{$asset->uuid}].");
        }

        $encKey = HkdfKeys::encryptionKey($this->keys->unwrap($mediaFile->dek_wrapped));
        $handle = Storage::disk('variants')->readStream($asset->path);

        if (! is_resource($handle)) {
            throw new RuntimeException("Could not open asset [{$asset->uuid}].");
        }

        try {
            $alignedStart = intdiv($start, 16) * 16;
            $skip = $start - $alignedStart;

            if ($alignedStart > 0 && fseek($handle, $alignedStart) !== 0) {
                throw new RuntimeException('Could not seek within asset.');
            }

            $offset = $alignedStart;
            $remaining = $length;

            while ($remaining > 0 && ! feof($handle)) {
                $cipherText = fread($handle, self::BLOCK);

                if ($cipherText === false || $cipherText === '') {
                    break;
                }

                $plain = $this->cipher->transformAt($cipherText, $encKey, $nonce, $offset);
                $offset += strlen($cipherText);

                if ($skip > 0) {
                    $plain = (string) substr($plain, $skip);
                    $skip = 0;
                }

                if (strlen($plain) > $remaining) {
                    $plain = (string) substr($plain, 0, $remaining);
                }

                $remaining -= strlen($plain);

                yield $plain;
            }
        } finally {
            fclose($handle);
        }
    }
}
