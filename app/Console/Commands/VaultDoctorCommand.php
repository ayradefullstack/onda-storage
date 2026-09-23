<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Vault\Doctor\CheckResult;
use App\Domain\Vault\Doctor\VaultDoctor;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Throwable;

final class VaultDoctorCommand extends Command
{
    protected $signature = 'vault:doctor
        {--fpm : Also fetch results from the web endpoint (FPM SAPI) and compare}
        {--json : Output raw JSON instead of a table}';

    protected $description = 'Audit the local environment for ONDA vault readiness (CLI and, with --fpm, FPM).';

    public function handle(VaultDoctor $doctor): int
    {
        $cli = $doctor->run();

        if ($this->option('fpm')) {
            return $this->compareWithFpm($cli);
        }

        if ($this->option('json')) {
            $json = json_encode($cli->map(fn (CheckResult $r) => $r->toArray())->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->line($json !== false ? $json : '[]');
        } else {
            $this->renderTable($cli);
        }

        return $cli->contains(fn (CheckResult $r) => $r->status === CheckResult::FAIL) ? 1 : 0;
    }

    /**
     * @param  Collection<int, CheckResult>  $results
     */
    private function renderTable(Collection $results): void
    {
        $this->table(
            ['Status', 'Check', 'Value', 'Rationale'],
            $results->map(fn (CheckResult $r) => [$r->status, $r->label, $r->value, $r->rationale])->all(),
        );

        $this->newLine();
        $this->line(sprintf(
            'PASS: %d  WARN: %d  FAIL: %d',
            $results->where('status', CheckResult::PASS)->count(),
            $results->where('status', CheckResult::WARN)->count(),
            $results->where('status', CheckResult::FAIL)->count(),
        ));
    }

    /**
     * @param  Collection<int, CheckResult>  $cli
     */
    private function compareWithFpm(Collection $cli): int
    {
        $url = URL::temporarySignedRoute('vault-doctor', now()->addMinutes(2));

        try {
            $response = Http::timeout(10)->get($url);
        } catch (Throwable $e) {
            $this->error('Could not reach the web server at '.config('app.url').' — is it running under FPM (e.g. via Herd)?');
            $this->line($e->getMessage());

            return 1;
        }

        if (! $response->successful()) {
            $this->error("Web endpoint returned HTTP {$response->status()}.");
            $this->line($response->body());

            return 1;
        }

        /** @var array<int, array<string, mixed>> $fpmChecks */
        $fpmChecks = (array) $response->json('checks', []);
        $fpmByKey = collect($fpmChecks)->keyBy('key');

        $rows = [];
        $hasFail = false;

        foreach ($cli as $check) {
            $fpm = $fpmByKey->get($check->key);
            $fpmValue = $fpm['value'] ?? 'n/a';
            $fpmStatus = $fpm['status'] ?? null;
            $differs = $fpmValue !== $check->value;

            if ($check->status === CheckResult::FAIL || $fpmStatus === CheckResult::FAIL) {
                $hasFail = true;
            }

            if ($check->key === 'php.version' && $differs) {
                $hasFail = true;
            }

            $label = $check->label.($check->sapiSensitive ? ' [sapi]' : '');
            $rows[] = [$label, $check->value, $fpmValue.($differs ? ' *' : '')];
        }

        $this->table(['Check', 'CLI', 'FPM'], $rows);
        $this->newLine();
        $this->line('* differs between CLI and FPM. Only the FPM column governs upload behaviour — [sapi] marks checks known to depend on it.');

        return $hasFail ? 1 : 0;
    }
}
