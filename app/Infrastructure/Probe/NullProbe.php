<?php

declare(strict_types=1);

namespace App\Infrastructure\Probe;

use App\Domain\Vault\Contracts\MediaProbe;
use Illuminate\Support\Facades\Log;

/**
 * ffprobe absent (or `MEDIA_PROBE_DRIVER=null`) → null metadata, not a
 * failure. A deposit's legal validity never depends on knowing its
 * duration or dimensions.
 */
final class NullProbe implements MediaProbe
{
    public function probe(string $absolutePath): array
    {
        Log::debug('NullProbe: skipping metadata extraction (MEDIA_PROBE_DRIVER=null or ffprobe absent).', ['path' => $absolutePath]);

        return ['duration_sec' => null, 'width' => null, 'height' => null];
    }
}
