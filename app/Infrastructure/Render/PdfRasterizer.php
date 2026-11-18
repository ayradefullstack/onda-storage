<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use RuntimeException;

/**
 * PDF -> page images (WebP, long edge `vault.consult.page_max_edge`), shared by
 * the PDF renderer and the LibreOffice path (document / presentation /
 * oversized spreadsheet), which hands over its intermediate PDF.
 *
 * pdftoppm first; otherwise Imagick (which itself needs Ghostscript for PDF).
 * Neither present -> null, and the caller shows the unsupported card.
 */
final class PdfRasterizer
{
    public function __construct(
        private readonly ToolLocator $tools,
        private readonly ToolRunner $runner,
    ) {}

    public function available(): bool
    {
        return $this->tools->find('pdftoppm_binary') !== null || extension_loaded('imagick');
    }

    /**
     * @return RenderResult unsupported when no rasterizer exists
     */
    public function rasterize(string $pdfPath, string $workspace): RenderResult
    {
        $max = (int) config('vault.consult.pdf_max_pages');
        $edge = (int) config('vault.consult.page_max_edge');

        $pages = null;
        $pdftoppm = $this->tools->find('pdftoppm_binary');

        if ($pdftoppm !== null) {
            $pages = $this->withPdftoppm($pdftoppm, $pdfPath, $workspace, $max, $edge);
        } elseif (extension_loaded('imagick')) {
            $pages = $this->withImagick($pdfPath, $workspace, $max, $edge);
        }

        if ($pages === null) {
            return RenderResult::unsupported('tool_missing:pdftoppm');
        }

        if ($pages === []) {
            return RenderResult::failed('render_failed');
        }

        $truncated = count($pages) > $max;
        $pages = array_slice($pages, 0, $max);

        $assets = [];

        foreach ($pages as $index => $path) {
            $assets[] = new RenderedAsset('page', $index, $path);
        }

        return RenderResult::ready($assets, count($pages), $truncated ? 'pages_truncated' : null);
    }

    /**
     * Renders one page beyond the cap so truncation is detectable without a
     * separate page-count tool.
     *
     * @return list<string>
     */
    private function withPdftoppm(string $binary, string $pdfPath, string $workspace, int $max, int $edge): array
    {
        $prefix = $workspace.DIRECTORY_SEPARATOR.'p';

        $run = $this->runner->run([
            $binary, '-png', '-scale-to', (string) $edge,
            '-f', '1', '-l', (string) ($max + 1),
            $pdfPath, $prefix,
        ], (int) config('vault.consult.convert_timeout'));

        if (! $run['ok']) {
            throw new RuntimeException($run['timedOut'] ? 'render_timeout' : 'pdftoppm failed: '.$run['output']);
        }

        $pngs = glob($prefix.'-*.png') ?: [];
        natsort($pngs);

        $out = [];

        foreach ($pngs as $png) {
            $webp = substr($png, 0, -4).'.webp';
            $this->pngToWebp($png, $webp);
            @unlink($png);
            $out[] = $webp;
        }

        return $out;
    }

    /**
     * @return list<string>|null
     */
    private function withImagick(string $pdfPath, string $workspace, int $max, int $edge): ?array
    {
        $out = [];

        try {
            for ($page = 0; $page <= $max; $page++) {
                $im = new \Imagick;
                $im->setResolution(150, 150);

                try {
                    $im->readImage($pdfPath.'['.$page.']');
                } catch (\Throwable) {
                    break;
                }

                $im->setImageFormat('webp');
                $im->setImageBackgroundColor('white');
                $im->thumbnailImage($edge, $edge, true);
                $im->stripImage();
                $path = $workspace.DIRECTORY_SEPARATOR.sprintf('p-%05d.webp', $page + 1);
                $im->writeImage($path);
                $im->clear();
                $out[] = $path;
            }
        } catch (\Throwable) {
            return $out === [] ? null : $out;
        }

        return $out;
    }

    private function pngToWebp(string $png, string $webp): void
    {
        $image = @imagecreatefrompng($png);

        if ($image === false) {
            throw new RuntimeException('Could not decode rendered page.');
        }

        imagepalettetotruecolor($image);
        $ok = imagewebp($image, $webp, 80);
        unset($image);

        if (! $ok) {
            throw new RuntimeException('Could not encode rendered page.');
        }
    }
}
