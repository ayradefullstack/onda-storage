<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;

/**
 * Infrastructure-internal strategy selected inside the derivative job — NOT a
 * domain port (the project stays at exactly four). Input is untrusted: every
 * implementation uses `Process` with an argument array, a hard timeout and
 * output confined to `$workspace`.
 */
interface ConsultationRenderer
{
    public function supports(PreviewFamily $family): bool;

    /**
     * @param  string  $sourcePath  decrypted plaintext of the original (pipeline scratch)
     * @param  string  $workspace  empty per-job directory for plaintext derivatives
     */
    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult;
}
