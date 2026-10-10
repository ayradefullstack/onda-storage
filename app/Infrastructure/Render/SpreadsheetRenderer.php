<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Models\MediaFile;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use ZipArchive;

/**
 * xls / xlsx / ods -> JSON `{sheets:[{name, rows:[[…]]}]}` with cell VALUES
 * only: a formula shows its cached result (never recalculated), and
 * hyperlinks, images, comments and macros are never read. PhpSpreadsheet is
 * pure PHP, so this works on cPanel without root.
 *
 * Input larger than `sheet_max_bytes` goes to the LibreOffice -> PDF path
 * instead (or an unsupported card without soffice).
 */
final class SpreadsheetRenderer implements ConsultationRenderer
{
    public function __construct(private readonly OfficeRenderer $office) {}

    public function supports(PreviewFamily $family): bool
    {
        return $family === PreviewFamily::Spreadsheet;
    }

    public function render(MediaFile $mediaFile, string $sourcePath, string $workspace): RenderResult
    {
        if (filesize($sourcePath) > (int) config('vault.consult.sheet_max_bytes')) {
            $result = $this->office->convertAndRasterize($mediaFile, $sourcePath, $workspace);

            return $result->status === 'unsupported' ? RenderResult::unsupported('too_large') : $result;
        }

        if ($this->looksLikeZipBomb($sourcePath)) {
            return RenderResult::unsupported('too_large');
        }

        $maxRows = (int) config('vault.consult.sheet_max_rows');
        $maxCols = (int) config('vault.consult.sheet_max_cols');

        $reader = IOFactory::createReaderForFile($sourcePath);
        // Values only: no styles, no hyperlinks, no drawings.
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        // Dimensions as DECLARED by the file, read before the capped load,
        // so truncation is reported even though the filter hides the excess.
        $declared = [];

        foreach ($reader->listWorksheetInfo($sourcePath) as $info) {
            $declared[$info['worksheetName']] = $info;
        }

        $reader->setReadFilter($this->filter($maxRows, $maxCols));

        $spreadsheet = $reader->load($sourcePath);
        $sheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $rows = [];
            $info = $declared[$sheet->getTitle()] ?? null;
            $truncated = $info !== null && ($info['totalRows'] > $maxRows || $info['totalColumns'] > $maxCols);
            $highestRow = $sheet->getHighestDataRow();
            $highestCol = min($maxCols, Coordinate::columnIndexFromString($sheet->getHighestDataColumn()));

            for ($r = 1; $r <= min($highestRow, $maxRows); $r++) {
                $row = [];

                for ($c = 1; $c <= $highestCol; $c++) {
                    $row[] = $sheet->cellExists([$c, $r]) ? $this->value($sheet->getCell([$c, $r])) : '';
                }

                $rows[] = $row;
            }

            $sheets[] = ['name' => $sheet->getTitle(), 'rows' => $rows, 'truncated' => $truncated];
        }

        $spreadsheet->disconnectWorksheets();

        $json = json_encode(['sheets' => $sheets], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $path = $workspace.DIRECTORY_SEPARATOR.'sheet.json';
        file_put_contents($path, $json);

        return RenderResult::ready([new RenderedAsset('sheet', 0, $path)], count($sheets));
    }

    private function value(Cell $cell): string
    {
        // Cached result of a formula; never trigger a recalculation of
        // untrusted formulas.
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_float($value) || is_int($value)) {
            if (! $cell->isFormula() && Date::isDateTime($cell)) {
                return Date::excelToDateTimeObject($value)->format('Y-m-d H:i:s');
            }

            return (string) $value;
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    private function filter(int $maxRows, int $maxCols): IReadFilter
    {
        return new class($maxRows, $maxCols) implements IReadFilter
        {
            public function __construct(private readonly int $maxRows, private readonly int $maxCols) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->maxRows
                    && Coordinate::columnIndexFromString($columnAddress) <= $this->maxCols;
            }
        };
    }

    /**
     * xlsx / ods are zip containers: refuse one whose declared uncompressed
     * size is wildly larger than the byte cap, before any parser sees it.
     */
    private function looksLikeZipBomb(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false; // not a zip (xls): the OLE reader handles it
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $total += (int) ($zip->statIndex($i)['size'] ?? 0);
        }

        $zip->close();

        return $total > (int) config('vault.consult.sheet_max_bytes') * 20;
    }
}
