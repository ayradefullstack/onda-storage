<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired whenever a job in the P5 pipeline moves `media_files.status`.
 * Observation only — the pipeline itself is `Bus::chain()`'d explicitly,
 * not event-driven (CLAUDE.md: "events are for side effects only"). This
 * exists so a later phase (status polling, notifications UI) can listen
 * without the pipeline jobs needing to know that phase exists.
 */
final class MediaFileStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $mediaFileUuid,
        public readonly string $previousStatus,
        public readonly string $newStatus,
    ) {}
}
