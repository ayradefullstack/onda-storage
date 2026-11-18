<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;

/**
 * One resized WebP, EXIF stripped (re-encoding drops every metadata block),
 * first frame / first page only. GD handles jpg, png, webp, gif, bmp; TIFF
 * needs Imagick, and without it the card says so.
 */
final class ImageRenderer implements ConsultationRenderer
{
    /** Decoded pixels above this are refused: a 20 kB PNG can decode to GBs. */
    private const MAX_PIXELS = 100_000_000;

    public function supports(PreviewFamily $family): bool
    {
        return $family === PreviewFamily::Image;
    }

    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        $edge = (int) config('vault.consult.image_max_edge');
        $info = @getimagesize($sourcePath);
        $extension = strtolower($mediaFile->extension);

        if (in_array($extension, ['tif', 'tiff'], true)) {
            return $this->viaImagick($sourcePath, $workspace, $edge);
        }

        if ($info === false) {
            return RenderResult::failed('render_failed');
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            return RenderResult::unsupported('too_large');
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            IMAGETYPE_BMP => @imagecreatefrombmp($sourcePath),
            default => false,
        };

        if ($image === false) {
            return RenderResult::failed('render_failed');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->orient($image, $sourcePath);
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) > $edge) {
            $scale = $edge / max($width, $height);
            $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

            if ($resized !== false) {
                $image = $resized;
            }
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $path = $workspace.DIRECTORY_SEPARATOR.'image.webp';

        if (! imagewebp($image, $path, 82)) {
            return RenderResult::failed('render_failed');
        }

        return RenderResult::ready(
            [new RenderedAsset('image', 0, $path, null, ['width' => imagesx($image), 'height' => imagesy($image)])],
            1,
            $this->isMultiFrame($sourcePath, $info[2]) ? 'first_frame_only' : null,
        );
    }

    private function viaImagick(string $sourcePath, string $workspace, int $edge): RenderResult
    {
        if (! extension_loaded('imagick')) {
            return RenderResult::unsupported('tool_missing:imagick');
        }

        try {
            $im = new \Imagick($sourcePath.'[0]');
            $im->setImageFormat('webp');
            $im->thumbnailImage($edge, $edge, true);
            $im->stripImage();
            $path = $workspace.DIRECTORY_SEPARATOR.'image.webp';
            $im->writeImage($path);
            $im->clear();
        } catch (\Throwable) {
            return RenderResult::failed('render_failed');
        }

        return RenderResult::ready([new RenderedAsset('image', 0, $path)], 1, 'first_frame_only');
    }

    private function orient(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }

    /**
     * An animated GIF / WebP has more than one frame; only the first is kept.
     */
    private function isMultiFrame(string $path, int $type): bool
    {
        if ($type !== IMAGETYPE_GIF) {
            return false;
        }

        $bytes = (string) file_get_contents($path, false, null, 0, 2_000_000);

        return preg_match_all('/\x00\x21\xF9\x04/', $bytes) > 1;
    }
}
