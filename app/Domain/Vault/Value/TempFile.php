<?php

declare(strict_types=1);

namespace App\Domain\Vault\Value;

/**
 * Wraps an absolute path to a plaintext temp file and deletes it on
 * destruction. This is what guarantees the only plaintext copy on disk
 * disappears even when a job throws mid-pipeline and never reaches an
 * explicit cleanup step.
 */
final class TempFile
{
    public function __construct(
        public readonly string $path,
    ) {}

    public function __destruct()
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
