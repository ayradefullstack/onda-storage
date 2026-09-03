<?php

declare(strict_types=1);

namespace App\Domain\Vault\Contracts;

/**
 * Probes a decrypted media file for basic technical metadata. `FfmpegProbe`
 * when the binary is available, `NullProbe` otherwise.
 */
interface MediaProbe
{
    /**
     * @return array{duration_sec: int|null, width: int|null, height: int|null}
     */
    public function probe(string $absolutePath): array;
}
