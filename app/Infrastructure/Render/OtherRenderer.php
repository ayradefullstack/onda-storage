<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;

/**
 * Archives, executables, unknown formats: a metadata card only. No bytes,
 * no URL.
 */
final class OtherRenderer implements ConsultationRenderer
{
    public function supports(PreviewFamily $family): bool
    {
        return in_array($family, [PreviewFamily::Other, PreviewFamily::Archive], true);
    }

    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        return RenderResult::unsupported('unsupported_format');
    }
}
