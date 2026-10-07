<?php

declare(strict_types=1);

namespace App\Actions\Upload;

use App\Domain\Vault\Contracts\ChunkTracker;
use App\Domain\Vault\Contracts\VaultContract;
use App\Models\UploadSession;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * The hot path — invoked ~640 times for a single 5 GB file. Every check here
 * is O(1) or a single indexed lookup; no FormRequest (a raw binary body
 * can't be validated by one — see CLAUDE.md and the controller).
 */
final class StoreChunk
{
    public function __construct(
        private readonly VaultContract $vault,
        private readonly ChunkTracker $tracker,
    ) {}

    /**
     * @return array{index: int, received: int, total: int, bytes: int}
     */
    public function handle(UploadSession $session, int $index, Request $request): array
    {
        $this->assertNotMultipart($request);
        $this->assertAcceptingChunks($session);
        $this->assertIndexInRange($session, $index);

        $bytes = (string) $request->getContent();

        $this->assertCrcMatches($request, $bytes, $index);
        $this->assertLengthMatches($session, $index, $bytes);

        $alreadyReceived = in_array($index, $this->tracker->receivedMask($session->uuid), true);

        if (! $alreadyReceived) {
            $this->vault->writeChunk($session, $index, $bytes);
            $session->refresh();
        }

        return [
            'index' => $index,
            'received' => $session->received_chunks,
            'total' => $session->total_chunks,
            'bytes' => $session->received_bytes,
        ];
    }

    private function assertNotMultipart(Request $request): void
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));

        if ($contentType !== '' && str_starts_with($contentType, 'multipart/')) {
            throw new HttpResponseException(response()->json([
                'message' => 'Chunk bodies must be sent as raw application/octet-stream, not multipart/form-data.',
            ], 415));
        }
    }

    private function assertAcceptingChunks(UploadSession $session): void
    {
        if ($session->expires_at->isPast()) {
            throw new HttpResponseException(response()->json([
                'message' => 'This upload session has expired.',
            ], 410));
        }

        if ($session->status !== 'uploading') {
            throw new HttpResponseException(response()->json([
                'message' => "This upload session is not accepting chunks (status: {$session->status}).",
            ], 409));
        }
    }

    private function assertIndexInRange(UploadSession $session, int $index): void
    {
        if ($index < 0 || $index >= $session->total_chunks) {
            throw new HttpResponseException(response()->json([
                'message' => "Chunk index {$index} is out of range for this upload (0..".($session->total_chunks - 1).').',
            ], 422));
        }
    }

    private function assertCrcMatches(Request $request, string $bytes, int $index): void
    {
        $header = strtolower(trim((string) $request->header('X-Chunk-CRC32', '')));

        if ($header === '') {
            throw new HttpResponseException(response()->json([
                'message' => "Missing X-Chunk-CRC32 header for chunk {$index}.",
                'index' => $index,
            ], 422));
        }

        $header = str_starts_with($header, '0x') ? substr($header, 2) : $header;
        $header = str_pad($header, 8, '0', STR_PAD_LEFT);

        $actual = hash('crc32b', $bytes);

        if (! hash_equals($actual, $header)) {
            throw new HttpResponseException(response()->json([
                'message' => "CRC32 mismatch for chunk {$index}; retry this chunk.",
                'index' => $index,
            ], 422));
        }
    }

    private function assertLengthMatches(UploadSession $session, int $index, string $bytes): void
    {
        $isFinal = $index === $session->total_chunks - 1;
        $expected = $isFinal
            ? $session->size_bytes - ($index * $session->chunk_size)
            : $session->chunk_size;

        if (strlen($bytes) !== $expected) {
            throw new HttpResponseException(response()->json([
                'message' => "Chunk {$index} must be exactly {$expected} bytes; received ".strlen($bytes).'.',
                'index' => $index,
            ], 422));
        }
    }
}
