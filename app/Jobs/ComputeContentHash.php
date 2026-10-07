<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Deposit\PipelineWorkspace;
use App\Models\MediaFile;
use RuntimeException;

/**
 * Streaming sha256 over the decrypted temp file — `hash_update_stream()`
 * never loads more than its internal read-buffer into memory, unlike
 * `hash('sha256', file_get_contents(...))`, which matters at up to 5 GiB.
 */
final class ComputeContentHash extends PipelineJob
{
    public int $timeout = 300;

    public function handle(): void
    {
        $mediaFile = MediaFile::where('uuid', $this->mediaFileUuid)->firstOrFail();
        $path = PipelineWorkspace::tempPath($this->mediaFileUuid);

        if (! is_file($path)) {
            throw new RuntimeException("Expected decrypted temp file for [{$this->mediaFileUuid}] at [{$path}] but it is missing.");
        }

        // A size mismatch means the stored bytes are not what was uploaded
        // — a correctness failure, not a warning, so this throws rather
        // than merely logging.
        $actualSize = filesize($path);

        if ($actualSize === false || $actualSize !== $mediaFile->size_bytes) {
            $actualSizeLabel = $actualSize === false ? 'unreadable' : (string) $actualSize;

            throw new RuntimeException(
                "Decrypted size mismatch for [{$this->mediaFileUuid}]: expected {$mediaFile->size_bytes} bytes, got {$actualSizeLabel}."
            );
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Could not open [{$path}] to hash.");
        }

        $context = hash_init('sha256');

        try {
            hash_update_stream($context, $handle);
        } finally {
            fclose($handle);
        }

        $mediaFile->sha256_plain = hash_final($context);
        $mediaFile->save();
    }
}
