<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Domain\Vault\Doctor\CheckResult;
use App\Models\MediaConsultation;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * `vault:doctor` additions for the consultation renderers. WARN, never FAIL:
 * a missing tool degrades a family to an unsupported card and blocks no
 * deposit.
 */
final class ConsultationChecks
{
    public function __construct(private readonly ToolLocator $tools) {}

    /**
     * @return Collection<int, CheckResult>
     */
    public function run(): Collection
    {
        $checks = collect();

        $checks->push($this->tool('consult.soffice', 'LibreOffice (soffice)', $this->tools->find('soffice_binary'),
            'Without it doc/docx/odt/rtf and ppt/pptx/odp show an "unsupported" card.'));

        $rasterizer = $this->tools->find('pdftoppm_binary') ?? (extension_loaded('imagick') ? 'imagick extension' : null);
        $checks->push($this->tool('consult.pdf', 'PDF rasterizer (pdftoppm / imagick)', $rasterizer,
            'Without one, PDFs (and converted documents) show an "unsupported" card.'));

        $checks->push($this->tool('consult.ffmpeg', 'ffmpeg (consultation video/audio)', $this->tools->find('ffmpeg_binary'),
            'Without it video and audio show an "unsupported" card.'));

        $checks->push(class_exists(IOFactory::class)
            ? new CheckResult('consult.spreadsheet', 'phpoffice/phpspreadsheet', CheckResult::PASS, 'installed', 'xls/xlsx/ods are read in pure PHP.')
            : new CheckResult('consult.spreadsheet', 'phpoffice/phpspreadsheet', CheckResult::WARN, 'missing', 'Spreadsheets fall back to LibreOffice, else an "unsupported" card.'));

        $checks->push($this->stuckPending());

        return $checks;
    }

    private function tool(string $key, string $label, ?string $path, string $rationale): CheckResult
    {
        return $path !== null
            ? new CheckResult($key, $label, CheckResult::PASS, $path, $rationale)
            : new CheckResult($key, $label, CheckResult::WARN, 'not found', $rationale);
    }

    private function stuckPending(): CheckResult
    {
        $threshold = (int) config('vault.consult.pending_warn_seconds');

        try {
            $stuck = MediaConsultation::where('status', MediaConsultation::PENDING)
                ->where('updated_at', '<', now()->subSeconds($threshold))
                ->count();
        } catch (Throwable) {
            return new CheckResult('consult.pending', 'Consultations stuck in pending', CheckResult::WARN, 'unknown', 'The media_consultations table could not be read (migrations pending?).');
        }

        return $stuck === 0
            ? new CheckResult('consult.pending', 'Consultations stuck in pending', CheckResult::PASS, '0', 'No derivative has been pending longer than the job timeout.')
            : new CheckResult('consult.pending', 'Consultations stuck in pending', CheckResult::WARN, (string) $stuck, "{$stuck} derivative(s) pending for over {$threshold}s: is the media queue worker running? Re-run vault:generate-previews --force.");
    }
}
