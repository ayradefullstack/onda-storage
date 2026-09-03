<?php

declare(strict_types=1);

namespace App\Infrastructure\Vault;

use App\Domain\Vault\Contracts\ChunkTracker;
use App\Domain\Vault\Contracts\VaultContract;
use App\Domain\Vault\Crypto\CtrCipher;
use App\Domain\Vault\Crypto\HkdfKeys;
use App\Domain\Vault\Crypto\KeyManager;
use App\Domain\Vault\Crypto\SegmentMac;
use App\Domain\Vault\Exceptions\IncompleteUpload;
use App\Domain\Vault\Exceptions\InsufficientStorage;
use App\Domain\Vault\Exceptions\ShortWrite;
use App\Domain\Vault\Value\ByteRange;
use App\Domain\Vault\Value\StoredObject;
use App\Domain\Vault\Value\TempFile;
use App\Domain\Vault\Value\UploadIntent;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * The local (Herd) implementation of the vault contract, built entirely on
 * native PHP file I/O against the `vault`/`incoming`/`work` disks.
 *
 * Deliberately local-driver-only: every absolute path is resolved via
 * `Storage::disk(...)->path()` because Flysystem has no seek-on-write, so
 * `Storage::put()` on a chunk would truncate the whole file. This confinement
 * to a single class is the seam — a future `EncryptedS3Vault` implements the
 * same `VaultContract` without any caller needing to change.
 */
final class EncryptedLocalVault implements VaultContract
{
    private const STREAM_BLOCK = 65536;

    public function __construct(
        private readonly KeyManager $keyManager,
        private readonly ChunkTracker $tracker,
        private readonly CtrCipher $cipher,
        private readonly SegmentMac $mac,
    ) {
        $chunkSize = (int) config('vault.chunk_size');
        $segmentSize = $this->mac->segmentSize();

        if ($chunkSize % $segmentSize !== 0) {
            throw new RuntimeException(
                "vault.chunk_size ({$chunkSize}) must be a whole multiple of vault.mac_segment_size ({$segmentSize})."
            );
        }
    }

    public function beginUpload(UploadIntent $intent): UploadSession
    {
        $quota = StorageQuota::where('user_id', $intent->userId)->first();

        if ($quota !== null && ($quota->used_bytes + $intent->sizeBytes) > $quota->limit_bytes) {
            throw new InsufficientStorage("Upload of {$intent->sizeBytes} bytes would exceed the storage quota for user {$intent->userId}.");
        }

        $incomingRoot = Storage::disk('incoming')->path('');
        $free = @disk_free_space($incomingRoot);

        if ($free === false || $free < $intent->sizeBytes * 2.1) {
            throw new InsufficientStorage(
                'Not enough free disk space to accept this upload — need at least 2.1x the declared size (the plaintext temp file the pipeline will later create doubles it).'
            );
        }

        $uuid = (string) Str::uuid7();
        $dek = $this->keyManager->generateDek();
        $nonce = $this->keyManager->generateNonce();
        $tempPath = "{$uuid}.part";

        // Create through Storage so the directory inherits correct
        // permissions, then switch to native I/O to pre-allocate.
        Storage::disk('incoming')->put($tempPath, '');
        $absolutePath = Storage::disk('incoming')->path($tempPath);

        $fh = fopen($absolutePath, 'r+b');

        if ($fh === false) {
            throw new RuntimeException("Could not open the temp file at [{$absolutePath}].");
        }

        try {
            if (! ftruncate($fh, max(0, $intent->sizeBytes))) {
                throw new RuntimeException("Could not pre-allocate {$intent->sizeBytes} bytes at [{$absolutePath}].");
            }
        } finally {
            fclose($fh);
        }

        $chunkSize = (int) config('vault.chunk_size');
        $totalChunks = max(1, (int) ceil($intent->sizeBytes / $chunkSize));

        // Direct attribute assignment rather than UploadSession::create():
        // the P1 model declares no $fillable, so mass assignment is guarded
        // by Eloquent's default `$guarded = ['*']`. Setting attributes one
        // at a time bypasses that guard without needing to touch the model
        // (out of this phase's ownership).
        $session = new UploadSession;
        $session->user_id = $intent->userId;
        $session->work_id = $intent->workId;
        $session->filename = $intent->filename;
        $session->size_bytes = $intent->sizeBytes;
        $session->chunk_size = $chunkSize;
        $session->total_chunks = $totalChunks;
        $session->received_chunks = 0;
        $session->received_bytes = 0;
        $session->dek_wrapped = $this->keyManager->wrap($dek);
        $session->nonce = bin2hex($nonce);
        $session->temp_path = $tempPath;
        $session->status = 'uploading';
        // Carbon::now() explicitly, not the now() helper: AppServiceProvider
        // swaps the app-wide default to CarbonImmutable, but the model casts
        // this column to the mutable Illuminate\Support\Carbon.
        $session->expires_at = Carbon::now()->addMinutes((int) config('vault.temp_ttl_minutes'));
        $session->save();

        return $session;
    }

    public function writeChunk(UploadSession $session, int $index, string $bytes): void
    {
        $dek = $this->keyManager->unwrap($session->dek_wrapped);
        $encKey = HkdfKeys::encryptionKey($dek);
        $macKey = HkdfKeys::macKey($dek);
        $nonce = $this->decodeNonce($session->nonce, $session->uuid);

        $chunkOffset = $index * $session->chunk_size;
        $ciphertext = $this->cipher->transformAt($bytes, $encKey, $nonce, $chunkOffset);

        $absolutePath = Storage::disk('incoming')->path($session->temp_path);
        $fh = fopen($absolutePath, 'r+b');

        if ($fh === false) {
            throw new RuntimeException("Could not open [{$absolutePath}] to write chunk {$index}.");
        }

        try {
            if (fseek($fh, $chunkOffset) !== 0) {
                throw new RuntimeException("Could not seek to offset {$chunkOffset} in [{$absolutePath}].");
            }

            $written = fwrite($fh, $ciphertext);
            fflush($fh);

            if ($written !== strlen($ciphertext)) {
                $writtenLen = $written === false ? 0 : $written;

                throw new ShortWrite(
                    "Wrote {$writtenLen} of ".strlen($ciphertext)." bytes for chunk {$index} of upload {$session->uuid}."
                );
            }
        } finally {
            fclose($fh);
        }

        $this->writeMacTagsForChunk($session, $chunkOffset, $ciphertext, $macKey);

        $this->tracker->markReceived($session->uuid, $index, strlen($bytes));
    }

    public function finalize(UploadSession $session): StoredObject
    {
        if (! $this->tracker->isComplete($session->uuid, $session->total_chunks)) {
            throw new IncompleteUpload("Upload [{$session->uuid}] is missing chunks; cannot finalize.");
        }

        $shard1 = substr($session->uuid, 0, 2);
        $shard2 = substr($session->uuid, 2, 2);
        $vaultRelativePath = "{$shard1}/{$shard2}/{$session->uuid}.bin";
        $macRelativePath = "{$shard1}/{$shard2}/{$session->uuid}.mac";

        $shardAbsolute = Storage::disk('vault')->path("{$shard1}/{$shard2}");

        if (! is_dir($shardAbsolute)) {
            mkdir($shardAbsolute, 0700, true);
        }

        $incomingAbsolute = Storage::disk('incoming')->path($session->temp_path);
        $incomingMacAbsolute = Storage::disk('incoming')->path($session->temp_path.'.mac');
        $vaultAbsolute = Storage::disk('vault')->path($vaultRelativePath);
        $vaultMacAbsolute = Storage::disk('vault')->path($macRelativePath);

        if (! rename($incomingAbsolute, $vaultAbsolute)) {
            throw new RuntimeException("Could not move upload [{$session->uuid}] into the vault.");
        }

        if (! rename($incomingMacAbsolute, $vaultMacAbsolute)) {
            throw new RuntimeException("Could not move the MAC sidecar for [{$session->uuid}] into the vault.");
        }

        $this->tracker->forget($session->uuid);

        return new StoredObject(
            uuid: $session->uuid,
            disk: 'vault',
            path: $vaultRelativePath,
            macPath: $macRelativePath,
            dekWrapped: $session->dek_wrapped,
            nonce: $session->nonce,
            sizeBytes: $session->size_bytes,
        );
    }

    public function readRange(StoredObject $object, ?ByteRange $range): StreamInterface
    {
        $dek = $this->keyManager->unwrap($object->dekWrapped);
        $encKey = HkdfKeys::encryptionKey($dek);
        $macKey = HkdfKeys::macKey($dek);
        $nonce = $this->decodeNonce($object->nonce, $object->uuid);

        $requested = $range ?? new ByteRange(0, max(0, $object->sizeBytes - 1));
        $aligned = $requested->alignedDown(16);
        $prefixLength = $requested->start - $aligned->start;
        $wantedLength = $requested->length();

        $absolutePath = Storage::disk($object->disk)->path($object->path);
        $macAbsolutePath = Storage::disk($object->disk)->path($object->macPath);

        $cipherHandle = fopen($absolutePath, 'rb');
        $macHandle = fopen($macAbsolutePath, 'rb');

        if ($cipherHandle === false || $macHandle === false) {
            throw new RuntimeException("Could not open stored object [{$object->uuid}] for reading.");
        }

        $this->mac->verifyRange($cipherHandle, $macHandle, $macKey, $object->uuid, $aligned->start, $requested->end);
        fclose($macHandle);

        fseek($cipherHandle, $aligned->start);

        $offset = $aligned->start;
        $remaining = $aligned->end - $aligned->start + 1;
        $skipped = 0;
        $delivered = 0;
        $cipher = $this->cipher;

        $readFn = function (int $length) use (
            $cipherHandle, $encKey, $nonce, &$offset, &$remaining, &$skipped, $prefixLength, &$delivered, $wantedLength, $cipher
        ): string {
            if ($delivered >= $wantedLength || $remaining <= 0) {
                return '';
            }

            $readLen = min(self::STREAM_BLOCK, $remaining);
            $ciphertext = fread($cipherHandle, $readLen);

            if ($ciphertext === false || $ciphertext === '') {
                return '';
            }

            $plain = $cipher->transformAt($ciphertext, $encKey, $nonce, $offset);
            $n = strlen($ciphertext);
            $offset += $n;
            $remaining -= $n;

            if ($skipped < $prefixLength) {
                $toSkip = min($prefixLength - $skipped, strlen($plain));
                $plain = substr($plain, $toSkip);
                $skipped += $toSkip;
            }

            if ($delivered + strlen($plain) > $wantedLength) {
                $plain = substr($plain, 0, $wantedLength - $delivered);
            }

            $delivered += strlen($plain);

            return $plain;
        };

        $eofFn = function () use (&$delivered, $wantedLength): bool {
            return $delivered >= $wantedLength;
        };

        $closeFn = function () use ($cipherHandle): void {
            if (is_resource($cipherHandle)) {
                fclose($cipherHandle);
            }
        };

        $getContentsFn = function () use ($readFn, $eofFn): string {
            $buffer = '';

            while (! $eofFn()) {
                $chunk = $readFn(self::STREAM_BLOCK);

                if ($chunk === '') {
                    break;
                }

                $buffer .= $chunk;
            }

            return $buffer;
        };

        return FnStream::decorate(Utils::streamFor(''), [
            'read' => $readFn,
            'eof' => $eofFn,
            'close' => $closeFn,
            'tell' => function () use (&$delivered): int {
                return $delivered;
            },
            'getSize' => function () use ($wantedLength): int {
                return $wantedLength;
            },
            'getContents' => $getContentsFn,
            'isReadable' => fn (): bool => true,
            'isWritable' => fn (): bool => false,
            'isSeekable' => fn (): bool => false,
            'seek' => function (): void {
                throw new RuntimeException('This stream is not seekable.');
            },
            'rewind' => function (): void {
                throw new RuntimeException('This stream is not seekable.');
            },
            '__toString' => function () use ($getContentsFn): string {
                try {
                    return $getContentsFn();
                } catch (\Throwable) {
                    return '';
                }
            },
        ]);
    }

    public function decryptToTemp(StoredObject $object): TempFile
    {
        $dek = $this->keyManager->unwrap($object->dekWrapped);
        $encKey = HkdfKeys::encryptionKey($dek);
        $nonce = $this->decodeNonce($object->nonce, $object->uuid);

        $filename = (string) Str::uuid7().'.tmp';
        Storage::disk('work')->put($filename, '');
        $absoluteOut = Storage::disk('work')->path($filename);

        $absoluteIn = Storage::disk($object->disk)->path($object->path);

        $in = fopen($absoluteIn, 'rb');
        $out = fopen($absoluteOut, 'r+b');

        if ($in === false || $out === false) {
            throw new RuntimeException("Could not open files to decrypt [{$object->uuid}] to a temp file.");
        }

        try {
            $this->cipher->streamTransform($in, $out, $encKey, $nonce, 0, $object->sizeBytes);
        } finally {
            fclose($in);
            fclose($out);
        }

        return new TempFile($absoluteOut);
    }

    public function destroy(StoredObject $object): void
    {
        $absolute = Storage::disk($object->disk)->path($object->path);
        $absoluteMac = Storage::disk($object->disk)->path($object->macPath);

        if (is_file($absolute)) {
            @unlink($absolute);
        }

        if (is_file($absoluteMac)) {
            @unlink($absoluteMac);
        }
    }

    private function decodeNonce(string $hex, string $uuidForError): string
    {
        $nonce = hex2bin($hex);

        if ($nonce === false) {
            throw new RuntimeException("Stored nonce for [{$uuidForError}] is not valid hex.");
        }

        return $nonce;
    }

    private function writeMacTagsForChunk(UploadSession $session, int $chunkOffset, string $ciphertext, string $macKey): void
    {
        $segmentSize = $this->mac->segmentSize();
        $macAbsolutePath = Storage::disk('incoming')->path($session->temp_path.'.mac');

        if (! is_file($macAbsolutePath)) {
            touch($macAbsolutePath);
        }

        $fh = fopen($macAbsolutePath, 'r+b');

        if ($fh === false) {
            throw new RuntimeException("Could not open MAC sidecar at [{$macAbsolutePath}].");
        }

        try {
            $length = strlen($ciphertext);
            $localOffset = 0;

            // Chunk boundaries are guaranteed (by the constructor guard) to
            // land on segment boundaries, so every slice below is exactly
            // one whole segment's worth of ciphertext — never a fragment
            // that would be re-tagged incorrectly when the neighbouring
            // chunk arrives.
            while ($localOffset < $length) {
                $globalOffset = $chunkOffset + $localOffset;
                $segIndex = intdiv($globalOffset, $segmentSize);
                $segLocalStart = $globalOffset % $segmentSize;
                $sliceLen = min($segmentSize - $segLocalStart, $length - $localOffset);
                $slice = substr($ciphertext, $localOffset, $sliceLen);

                $tag = $this->mac->tagFor($macKey, $session->uuid, $segIndex, $slice);
                $this->mac->writeTag($fh, $segIndex, $tag);

                $localOffset += $sliceLen;
            }
        } finally {
            fclose($fh);
        }
    }
}
