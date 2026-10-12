<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;
use App\Support\FileFormats;

/**
 * The ONE place that maps a format to a preview family. Input is the format
 * registry's category for the file's extension plus a verified MIME type —
 * never the client-supplied extension alone: `VerifyContentType` has already
 * rejected any file whose finfo type is not one of the registry's types for
 * its extension, so a stored extension is a registry-checked one.
 *
 * `$verifiedMime` is the finfo type measured on the decrypted bytes when the
 * caller has them (the derivative job). Without it the registry's own list
 * of types for the extension stands in; the persisted
 * `media_consultations.family` is authoritative once a job has run.
 */
final class PreviewFamilyResolver
{
    /** MIME types whose content is plain text, safe to show as source. */
    public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const TEXT_MIMES = ['text/plain'];

    public function resolve(MediaFile $mediaFile, ?string $verifiedMime = null): PreviewFamily
    {
        return $this->forExtension($mediaFile->extension, $verifiedMime);
    }

    /**
     * How this family reaches the reviewer, from `vault.consult.delivery`:
     * the `family:ext` key wins over the bare `family` key; anything missing
     * or unknown is `unsupported` (fail closed — never an original by default).
     */
    public function deliveryMode(PreviewFamily $family, string $extension): DeliveryMode
    {
        $map = (array) config('vault.consult.delivery', []);
        $value = $map[$family->value.':'.strtolower($extension)] ?? $map[$family->value] ?? null;

        return is_string($value) ? (DeliveryMode::tryFrom($value) ?? DeliveryMode::Unsupported) : DeliveryMode::Unsupported;
    }

    /**
     * The mode for a stored file: the family the consultation row recorded
     * (authoritative once a job ran), else the registry's guess.
     */
    public function modeFor(MediaFile $mediaFile): DeliveryMode
    {
        $recorded = $mediaFile->consultation()->value('family');
        $family = is_string($recorded) ? (PreviewFamily::tryFrom($recorded) ?? PreviewFamily::Other) : $this->resolve($mediaFile);

        return $this->deliveryMode($family, $mediaFile->extension);
    }

    /**
     * The Content-Type an `original_inline` response may carry: the file's
     * verified stored MIME, and only when it belongs to one of the four
     * families (PDF, DOCX, video/*, audio/*) AND is one the format registry
     * accepts for that extension. Anything else is null (404).
     */
    public function inlineMime(MediaFile $mediaFile): ?string
    {
        $mime = strtolower($mediaFile->mime);
        $format = FileFormats::get(strtolower($mediaFile->extension));

        if ($format === null || ! in_array($mime, array_map('strtolower', [...$format['mimes'], $format['stored_mime']]), true)) {
            return null;
        }

        // A real docx is measured as application/zip by finfo; the registry
        // accepts both and the response carries the proper docx type.
        if (strtolower($mediaFile->extension) === 'docx') {
            return self::DOCX_MIME;
        }

        $allowed = $mime === 'application/pdf'
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/');

        return $allowed ? $mime : null;
    }

    public function forExtension(string $extension, ?string $verifiedMime = null): PreviewFamily
    {
        $extension = strtolower($extension);
        $format = FileFormats::get($extension);

        if ($format === null) {
            return PreviewFamily::Other;
        }

        return match ($format['category']) {
            FileFormats::CATEGORY_DOCUMENTS => $this->document($extension),
            FileFormats::CATEGORY_SPREADSHEETS => $extension === 'csv' ? PreviewFamily::Csv : PreviewFamily::Spreadsheet,
            FileFormats::CATEGORY_PRESENTATIONS => PreviewFamily::Presentation,
            FileFormats::CATEGORY_IMAGES => $this->image($extension, $verifiedMime),
            FileFormats::CATEGORY_AUDIO => PreviewFamily::Audio,
            FileFormats::CATEGORY_VIDEO => PreviewFamily::Video,
            FileFormats::CATEGORY_ARCHIVES => $extension === 'zip' ? PreviewFamily::Archive : PreviewFamily::Other,
            FileFormats::CATEGORY_DATA => $this->data($extension),
            // ebooks and notation: other, unless the verified MIME is plain
            // text or XML.
            default => $this->textLike($format['mimes'], $verifiedMime) ? PreviewFamily::Text : PreviewFamily::Other,
        };
    }

    private function document(string $extension): PreviewFamily
    {
        return match ($extension) {
            'pdf' => PreviewFamily::Pdf,
            'txt' => PreviewFamily::Text,
            default => PreviewFamily::Document,
        };
    }

    private function image(string $extension, ?string $verifiedMime): PreviewFamily
    {
        return match ($extension) {
            // SVG is shown as source text, never rendered as markup.
            'svg' => PreviewFamily::Text,
            // An .ai file IS a PDF; an older PostScript one is not.
            'ai' => $verifiedMime === 'application/pdf' ? PreviewFamily::Pdf : PreviewFamily::Other,
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff' => PreviewFamily::Image,
            default => PreviewFamily::Other,
        };
    }

    private function data(string $extension): PreviewFamily
    {
        return match ($extension) {
            'json', 'xml', 'sql' => PreviewFamily::Text,
            default => PreviewFamily::Other,
        };
    }

    /**
     * @param  list<string>  $registryMimes
     */
    private function textLike(array $registryMimes, ?string $verifiedMime): bool
    {
        $mimes = $verifiedMime !== null ? [$verifiedMime] : $registryMimes;

        foreach ($mimes as $mime) {
            if (! in_array($mime, self::TEXT_MIMES, true) && ! $this->isXml($mime)) {
                return false;
            }
        }

        return $mimes !== [];
    }

    private function isXml(string $mime): bool
    {
        return in_array($mime, ['text/xml', 'application/xml'], true) || str_ends_with($mime, '+xml');
    }
}
