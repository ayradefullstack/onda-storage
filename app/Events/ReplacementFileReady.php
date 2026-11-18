<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A replacement file (uploaded during review into the slot of a rejected
 * file the author removed) has reached `ready`: the officer can now decide
 * on it. Dispatched by TransitionMediaFileStatus, after commit, only for
 * files with `replaces_media_file_id`. A side effect only — the pipeline
 * does not depend on it.
 */
final class ReplacementFileReady implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $mediaFileUuid,
    ) {}
}
