<?php

declare(strict_types=1);

namespace App\Infrastructure\Tracker;

use App\Domain\Vault\Contracts\ChunkTracker;
use Illuminate\Support\Facades\Redis;

/**
 * Tracks received chunks with SETBIT/INCRBY — atomic, no row locks. A 5 GB
 * file at 8 MiB chunks is ~640 concurrent updates to the same logical
 * record; SETBIT makes each one an independent atomic operation instead of
 * 640 contending writes to one database row.
 */
final class RedisChunkTracker implements ChunkTracker
{
    public function markReceived(string $uuid, int $index, int $bytes): void
    {
        $previous = (int) Redis::setbit($this->maskKey($uuid), $index, true);

        if ($previous === 0) {
            Redis::incrby($this->bytesKey($uuid), $bytes);
        }
    }

    public function receivedMask(string $uuid): array
    {
        $raw = Redis::get($this->maskKey($uuid));

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        return $this->decodeMask($raw);
    }

    public function isComplete(string $uuid, int $total): bool
    {
        return count($this->receivedMask($uuid)) >= $total;
    }

    public function forget(string $uuid): void
    {
        Redis::del($this->maskKey($uuid), $this->bytesKey($uuid));
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

    private function maskKey(string $uuid): string
    {
        return "vault:upload:{$uuid}:mask";
    }

    private function bytesKey(string $uuid): string
    {
        return "vault:upload:{$uuid}:bytes";
    }
}
