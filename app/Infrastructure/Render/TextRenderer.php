<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;

/**
 * txt, md-like, json, xml, svg, srt, vtt, sql ... -> plain UTF-8 text,
 * capped at `vault.consult.text_max_bytes`. SVG and XML are shown as SOURCE:
 * they are never rendered as markup anywhere (served `text/plain`, shown
 * through Vue's escaped interpolation).
 */
final class TextRenderer implements ConsultationRenderer
{
    public function supports(PreviewFamily $family): bool
    {
        return $family === PreviewFamily::Text;
    }

    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        $max = (int) config('vault.consult.text_max_bytes');
        $handle = fopen($sourcePath, 'rb');

        if ($handle === false) {
            return RenderResult::failed('read_failed');
        }

        $bytes = (string) fread($handle, max(1, $max));
        $truncated = ! feof($handle);
        fclose($handle);

        // A cut can split a multibyte sequence; normalise() repairs the tail
        // by falling back, so trim an incomplete UTF-8 tail first.
        if ($truncated) {
            $bytes = (string) mb_strcut($bytes, 0, strlen($bytes), 'UTF-8');
        }

        $text = Utf8::normalise($bytes);
        $path = $workspace.DIRECTORY_SEPARATOR.'text.txt';
        file_put_contents($path, $text);

        return RenderResult::ready(
            [new RenderedAsset('text', 0, $path, null, ['truncated' => $truncated])],
            null,
            $truncated ? 'text_truncated' : null,
        );
    }
}
