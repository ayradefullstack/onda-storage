<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;

/**
 * Documents and presentations: LibreOffice -> PDF in the workspace -> the
 * same page-image path as a PDF. Without soffice the family shows the
 * unsupported card with the exact reason.
 */
final class OfficeRenderer implements ConsultationRenderer
{
    public function __construct(
        private readonly OfficeConverter $office,
        private readonly PdfRasterizer $rasterizer,
    ) {}

    public function supports(PreviewFamily $family): bool
    {
        return in_array($family, [PreviewFamily::Document, PreviewFamily::Presentation], true);
    }

    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        return $this->convertAndRasterize($mediaFile, $sourcePath, $workspace);
    }

    /**
     * Also used by the spreadsheet renderer for oversized inputs.
     */
    public function convertAndRasterize(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        if (! $this->office->available()) {
            return RenderResult::unsupported('tool_missing:soffice');
        }

        if (! $this->rasterizer->available()) {
            return RenderResult::unsupported('tool_missing:pdftoppm');
        }

        $input = $this->office->stage($sourcePath, $workspace, $mediaFile->extension);
        $pdf = $this->office->toPdf($input, $workspace);

        try {
            return $this->rasterizer->rasterize($pdf, $workspace);
        } finally {
            // The intermediate PDF is plaintext: gone as soon as it is used.
            @unlink($pdf);
            @unlink($input);
        }
    }
}
