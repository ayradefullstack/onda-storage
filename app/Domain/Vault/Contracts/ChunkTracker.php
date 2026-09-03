<?php

declare(strict_types=1);

namespace App\Domain\Vault\Contracts;

/**
 * Tracks which chunks of an in-progress upload have been received, keyed by
 * the upload session's uuid. Implementations must be safe under concurrent
 * writes to disjoint chunk indices of the same upload (up to 3 concurrent
 * chunks per CLAUDE.md) without row-locking the whole session per chunk.
 */
interface ChunkTracker
{
    public function markReceived(string $uuid, int $index, int $bytes): void;

    /**
     * @return list<int> sorted, deduplicated chunk indices received so far
     */
    public function receivedMask(string $uuid): array;

    public function isComplete(string $uuid, int $total): bool;

    public function forget(string $uuid): void;
}
