<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;

final class PdfRenderer implements ConsultationRenderer
{
    public function __construct(private readonly PdfRasterizer $rasterizer) {}

    public function supports(PreviewFamily $family): bool
    {
        return $family === PreviewFamily::Pdf;
    }

    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        return $this->rasterizer->rasterize($sourcePath, $workspace);
    }
}
