<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;
use SplFileObject;

/**
 * CSV -> the same JSON shape as a spreadsheet (one sheet). Parsed with
 * SplFileObject after normalising the encoding to UTF-8, with the delimiter
 * sniffed from the first line (`,` `;` tab `|`), capped at the sheet caps.
 */
final class CsvRenderer implements ConsultationRenderer
{
    public function supports(PreviewFamily $family): bool
    {
        return $family === PreviewFamily::Csv;
    }

    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        $maxRows = (int) config('vault.consult.sheet_max_rows');
        $maxCols = (int) config('vault.consult.sheet_max_cols');
        $maxBytes = (int) config('vault.consult.sheet_max_bytes');

        // Read a bounded prefix, normalise it, parse from a UTF-8 scratch copy.
        $handle = fopen($sourcePath, 'rb');

        if ($handle === false) {
            return RenderResult::failed('read_failed');
        }

        $bytes = (string) fread($handle, max(1, $maxBytes));
        $truncatedBytes = ! feof($handle);
        fclose($handle);

        $utf8Path = $workspace.DIRECTORY_SEPARATOR.'csv-utf8.csv';
        file_put_contents($utf8Path, Utf8::normalise($bytes));

        $delimiter = $this->sniff($utf8Path);
        $file = new SplFileObject($utf8Path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter, '"', '');

        $rows = [];
        $truncated = $truncatedBytes;

        foreach ($file as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (count($rows) >= $maxRows) {
                $truncated = true;
                break;
            }

            $rows[] = array_map(static fn ($cell): string => (string) $cell, array_slice($row, 0, $maxCols));
        }

        unset($file);
        @unlink($utf8Path);

        $json = json_encode(
            ['sheets' => [['name' => 'CSV', 'rows' => $rows, 'truncated' => $truncated]]],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
        $path = $workspace.DIRECTORY_SEPARATOR.'sheet.json';
        file_put_contents($path, $json);

        return RenderResult::ready([new RenderedAsset('sheet', 0, $path)], 1);
    }

    private function sniff(string $path): string
    {
        $line = (string) (new SplFileObject($path, 'r'))->fgets();
        $best = ',';
        $bestCount = 0;

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($line, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
