<?php

declare(strict_types=1);

namespace App\Infrastructure\Tracker;

use App\Domain\Vault\Contracts\ChunkTracker;
use App\Models\UploadSession;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tracks received chunks in `upload_sessions.chunk_mask` — a plain bit per
 * chunk index, MSB-first per byte (matching Redis's own SETBIT/GETBIT
 * ordering, so both trackers decode an identical sequence identically).
 * Updates happen inside a short row-locked transaction; fine for the
 * expected concurrency of up to 3 chunks per upload.
 */
final class DatabaseChunkTracker implements ChunkTracker
{
    public function markReceived(string $uuid, int $index, int $bytes): void
    {
        DB::transaction(function () use ($uuid, $index, $bytes): void {
            $session = UploadSession::where('uuid', $uuid)->lockForUpdate()->first();

            if ($session === null) {
                throw new RuntimeException("Unknown upload session [{$uuid}].");
            }

            $mask = (string) ($session->chunk_mask ?? '');
            $byteIndex = intdiv($index, 8);
            $bitIndex = 7 - ($index % 8);

            if (strlen($mask) <= $byteIndex) {
                $mask = str_pad($mask, $byteIndex + 1, "\0");
            }

            $byte = ord($mask[$byteIndex]);
            $alreadySet = ($byte >> $bitIndex) & 1;

            if (! $alreadySet) {
                $mask[$byteIndex] = chr($byte | (1 << $bitIndex));
                $session->received_bytes += $bytes;
            }

            $session->chunk_mask = $mask;
            $session->received_chunks = $this->countSetBits($mask);
            $session->save();
        });
    }

    public function receivedMask(string $uuid): array
    {
        $session = UploadSession::where('uuid', $uuid)->first();
        $mask = $session?->chunk_mask;

        if (! is_string($mask) || $mask === '') {
            return [];
        }

        return $this->decodeMask($mask);
    }

    public function isComplete(string $uuid, int $total): bool
    {
        return count($this->receivedMask($uuid)) >= $total;
    }

    public function forget(string $uuid): void
    {
        UploadSession::where('uuid', $uuid)->update([
            'chunk_mask' => null,
            'received_chunks' => 0,
            'received_bytes' => 0,
        ]);
    }

    /**
     * @return list<int>
     */
    private function decodeMask(string $mask): array
    {
        $indices = [];
        $length = strlen($mask);

        for ($byteIndex = 0; $byteIndex < $length; $byteIndex++) {
            $byte = ord($mask[$byteIndex]);

            if ($byte === 0) {
                continue;
            }

            for ($bit = 0; $bit < 8; $bit++) {
                if (($byte >> (7 - $bit)) & 1) {
                    $indices[] = ($byteIndex * 8) + $bit;
                }
            }
        }

        sort($indices);

        return $indices;
    }

    private function countSetBits(string $mask): int
    {
        return count($this->decodeMask($mask));
    }
}
