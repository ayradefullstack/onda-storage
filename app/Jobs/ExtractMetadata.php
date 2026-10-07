<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Deposit\PipelineWorkspace;
use App\Domain\Vault\Contracts\MediaProbe;
use App\Models\MediaFile;
use RuntimeException;

/**
 * `MediaProbe` implementations never throw (see `FfmpegProbe`/`NullProbe`)
 * — every failure mode degrades to null duration/width/height. A deposit's
 * legal validity never depends on knowing them.
 */
final class ExtractMetadata extends PipelineJob
{
    public int $timeout = 300;

    public function handle(MediaProbe $probe): void
    {
        $mediaFile = MediaFile::where('uuid', $this->mediaFileUuid)->firstOrFail();
        $path = PipelineWorkspace::tempPath($this->mediaFileUuid);

        if (! is_file($path)) {
            throw new RuntimeException("Expected decrypted temp file for [{$this->mediaFileUuid}] at [{$path}] but it is missing.");
        }

        $metadata = $probe->probe($path);

        $mediaFile->duration_sec = $metadata['duration_sec'];
        $mediaFile->width = $metadata['width'];
        $mediaFile->height = $metadata['height'];
        $mediaFile->save();
    }
}
