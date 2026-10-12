<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * pptx -> a text outline, in pure PHP (ZipArchive + DOM): no LibreOffice.
 * `ppt/slides/slide{N}.xml` in numeric order, the `a:t` runs of every
 * paragraph, plus the speaker notes reached through the slide's relationship
 * file. NOT a visual rendering — the viewer says so.
 *
 * The XML is untrusted: parsed with LIBXML_NONET and never LIBXML_NOENT, and
 * a part carrying a DOCTYPE / ENTITY declaration is skipped outright, so an
 * entity can never be expanded (XXE, billion laughs). Caps: slide count
 * (`pptx_max_slides`), total text bytes (`pptx_max_text_bytes`), and one
 * part's inflated size.
 */
final class PptxOutlineRenderer implements ConsultationRenderer
{
    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** One XML part, inflated. A real slide is a few hundred KiB at most. */
    private const MAX_PART_BYTES = 8 * 1024 * 1024;

    public function supports(PreviewFamily $family): bool
    {
        return $family === PreviewFamily::Presentation;
    }

    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        $zip = new ZipArchive;

        if ($zip->open($sourcePath, ZipArchive::RDONLY) !== true) {
            return RenderResult::failed('render_failed');
        }

        $slides = [];
        $notice = null;

        try {
            $slideNames = $this->slideNames($zip);

            if ($slideNames === []) {
                return RenderResult::failed('render_failed');
            }

            $maxSlides = max(1, (int) config('vault.consult.pptx_max_slides'));
            $maxText = max(1, (int) config('vault.consult.pptx_max_text_bytes'));
            $textBytes = 0;

            if (count($slideNames) > $maxSlides) {
                $notice = 'pages_truncated';
            }

            foreach (array_slice($slideNames, 0, $maxSlides, true) as $number => $name) {
                $texts = $this->paragraphs($this->xpath($zip, $name), '//a:p');
                $notes = $this->notes($zip, $number);

                foreach ([...$texts, ...$notes] as $line) {
                    $textBytes += strlen($line);
                }

                if ($textBytes > $maxText) {
                    $notice = 'text_truncated';

                    break;
                }

                $slides[] = ['index' => count($slides) + 1, 'texts' => $texts, 'notes' => $notes];
            }
        } finally {
            $zip->close();
        }

        $path = $workspace.DIRECTORY_SEPARATOR.'outline.json';
        file_put_contents($path, json_encode(['slides' => $slides], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

        return RenderResult::ready([new RenderedAsset('outline', 0, $path)], count($slides), $notice);
    }

    /**
     * @return array<int, string> slide number => part name, numeric order
     */
    private function slideNames(ZipArchive $zip): array
    {
        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m) === 1) {
                $names[(int) $m[1]] = $name;
            }
        }

        ksort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function notes(ZipArchive $zip, int $slideNumber): array
    {
        $rels = $this->xpath($zip, "ppt/slides/_rels/slide{$slideNumber}.xml.rels");

        if ($rels === null) {
            return [];
        }

        $target = null;

        foreach ($rels->query('//r:Relationship') ?: [] as $rel) {
            if ($rel instanceof DOMElement && str_ends_with($rel->getAttribute('Type'), '/notesSlide')) {
                $target = $rel->getAttribute('Target');
            }
        }

        if ($target === null || preg_match('#^(?:\.\./)?notesSlides/(notesSlide\d+\.xml)$#', $target, $m) !== 1) {
            return [];
        }

        // Only the notes body placeholder: the slide-number / slide-image
        // placeholders of a notes page are not notes.
        return $this->paragraphs(
            $this->xpath($zip, 'ppt/notesSlides/'.$m[1]),
            "//p:sp[p:nvSpPr/p:nvPr/p:ph[@type='body']]//a:p",
        );
    }

    /**
     * @return list<string>
     */
    private function paragraphs(?DOMXPath $xpath, string $query): array
    {
        if ($xpath === null) {
            return [];
        }

        $lines = [];

        foreach ($xpath->query($query) ?: [] as $paragraph) {
            if (! $paragraph instanceof DOMElement) {
                continue;
            }

            $line = '';

            foreach ($paragraph->getElementsByTagNameNS(self::NS_A, 't') as $run) {
                $line .= $run->textContent;
            }

            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function xpath(ZipArchive $zip, string $name): ?DOMXPath
    {
        $stat = $zip->statName($name);

        if ($stat === false || $stat['size'] > self::MAX_PART_BYTES) {
            return null;
        }

        $xml = $zip->getFromName($name, self::MAX_PART_BYTES);

        // No DTD at all: an entity can then never be declared, let alone
        // expanded. Real OOXML parts never carry one.
        if (! is_string($xml) || preg_match('/<!(?:DOCTYPE|ENTITY)/i', $xml) === 1) {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded === false) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('a', self::NS_A);
        $xpath->registerNamespace('p', self::NS_P);
        $xpath->registerNamespace('r', self::NS_REL);

        return $xpath;
    }
}
