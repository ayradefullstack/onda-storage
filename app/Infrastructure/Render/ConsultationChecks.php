<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use App\Domain\Vault\Doctor\CheckResult;
use App\Models\MediaConsultation;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * `vault:doctor` additions for the consultation renderers. The binaries are
 * INFO when absent (optional: used only for a family configured `derivative`),
 * never WARN or FAIL, plus one INFO line with the effective delivery map.
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

        $optional = 'Optional, used only if a family is configured as derivative (vault.consult.delivery).';

        $checks->push($this->tool('consult.soffice', 'LibreOffice (soffice)', $this->tools->find('soffice_binary'), $optional));

        $rasterizer = $this->tools->find('pdftoppm_binary') ?? (extension_loaded('imagick') ? 'imagick extension' : null);
        $checks->push($this->tool('consult.pdf', 'PDF rasterizer (pdftoppm / imagick)', $rasterizer, $optional));

        $checks->push($this->tool('consult.ffmpeg', 'ffmpeg (consultation video/audio)', $this->tools->find('ffmpeg_binary'), $optional));

        $checks->push(new CheckResult('consult.delivery', 'Consultation delivery map', 'INFO', $this->deliveryMap(), 'family => mode, from vault.consult.delivery. original_inline = the browser renders the decrypted original; derivative = server-generated; unsupported = card.'));

        $checks->push(class_exists(IOFactory::class)
            ? new CheckResult('consult.spreadsheet', 'phpoffice/phpspreadsheet', CheckResult::PASS, 'installed', 'xls/xlsx/ods are read in pure PHP.')
            : new CheckResult('consult.spreadsheet', 'phpoffice/phpspreadsheet', CheckResult::WARN, 'missing', 'Spreadsheets fall back to LibreOffice, else an "unsupported" card.'));

        $checks->push($this->stuckPending());

        return $checks;
    }

    private function tool(string $key, string $label, ?string $path, string $rationale): CheckResult
    {
        // INFO, never WARN: production has none of these tools by design.
        return $path !== null
            ? new CheckResult($key, $label, CheckResult::PASS, $path, $rationale)
            : new CheckResult($key, $label, 'INFO', 'not found', $rationale);
    }

    private function deliveryMap(): string
    {
        $parts = [];

        foreach ((array) config('vault.consult.delivery', []) as $key => $mode) {
            $parts[] = $key.'='.(is_string($mode) ? $mode : '?');
        }

        return implode(', ', $parts);
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
