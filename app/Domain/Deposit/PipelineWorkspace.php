<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use Illuminate\Support\Facades\Storage;

/**
 * Names the ONE plaintext scratch file a media file's pipeline run uses,
 * deterministically from its uuid — deliberately NOT an object passed
 * between jobs.
 *
 * `Illuminate\Bus\Queueable::chain()` serializes every chained job
 * independently (`serializeJob()` is called once per job in the chain
 * array, not once for the whole array), so a shared `TempFile` instance
 * injected into every job's constructor would NOT stay one live object
 * across the chain — each job would unserialize its own separate copy. The
 * first job's copy would then be garbage-collected (and its destructor
 * would `unlink()` the file) as soon as that job's `handle()` returns,
 * deleting the file out from under every job after it. A deterministic path
 * string sidesteps that trap entirely: nothing after `DecryptToTemp` holds
 * a `TempFile` object for this path at all, so nothing destructs it away.
 * `CleanupTemp` — and every job's `failed()` — delete it explicitly.
 */
final class PipelineWorkspace
{
    public static function tempPath(string $mediaFileUuid): string
    {
        return Storage::disk('work')->path("{$mediaFileUuid}.tmp");
    }

    public static function exists(string $mediaFileUuid): bool
    {
        return is_file(self::tempPath($mediaFileUuid));
    }

    public static function delete(string $mediaFileUuid): void
    {
        @unlink(self::tempPath($mediaFileUuid));
    }
}
