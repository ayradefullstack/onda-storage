<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deposit\VaultConsistencyChecks;
use App\Domain\Vault\Doctor\CheckResult;
use App\Domain\Vault\Doctor\VaultDoctor;
use App\Infrastructure\Render\ConsultationChecks;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Throwable;

final class VaultDoctorCommand extends Command
{
    protected $signature = 'vault:doctor
        {--fpm : Also fetch results from the web endpoint (FPM SAPI) and compare}
        {--json : Output raw JSON instead of a table}';

    protected $description = 'Audit the local environment for ONDA vault readiness (CLI and, with --fpm, FPM).';

    public function handle(VaultDoctor $doctor, ConsultationChecks $consultation, VaultConsistencyChecks $consistency): int
    {
        // The consultation renderers' WARNs live next to the command, not in
        // the frozen Domain/Vault/Doctor.
        $cli = $doctor->run()->concat($consultation->run())->concat($consistency->run())->values();

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
            'PASS: %d  WARN: %d  FAIL: %d  INFO: %d',
            $results->where('status', CheckResult::PASS)->count(),
            $results->where('status', CheckResult::WARN)->count(),
            $results->where('status', CheckResult::FAIL)->count(),
            $results->where('status', 'INFO')->count(),
        ));
    }

    /**
     * @param  Collection<int, CheckResult>  $cli
     */
    private function compareWithFpm(Collection $cli): int
    {
        $url = URL::temporarySignedRoute('vault-doctor', now()->addMinutes(2));
        $resolveIp = config('vault.doctor_resolve_ip');
        $resolveIp = is_string($resolveIp) && $resolveIp !== '' ? $resolveIp : null;

        try {
            $options = $this->fpmHttpOptions($url, $resolveIp);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $resolvedVia = $resolveIp !== null ? " (resolved via {$resolveIp})" : '';

        if ($resolveIp !== null) {
            $this->line("FPM endpoint resolved via {$resolveIp} (VAULT_DOCTOR_RESOLVE_IP).");
        }

        try {
            $response = Http::timeout(10)->withOptions($options)->get($url);
        } catch (Throwable $e) {
            $this->error('Could not reach the web server at '.config('app.url').$resolvedVia.' — is it running under FPM (e.g. via Herd)?');
            $this->line($e->getMessage());

            return 1;
        }

        if (! $response->successful()) {
            $this->error("Web endpoint returned HTTP {$response->status()}.");

            if ($response->status() === 404 && ! app()->environment('local')) {
                $this->line('Endpoint disabled — set VAULT_DOCTOR_WEB_ENABLED=true temporarily, run optimize, then disable it again.');
            } else {
                $this->line($response->body());
            }

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
            // Outside `local` the endpoint returns only runtime checks; a key
            // it doesn't report is "not compared", not "differs".
            $differs = $fpm !== null && $fpmValue !== $check->value;

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

    /**
     * Guzzle options for the FPM self-request. With an override IP, pins the
     * URL's own host:port to it via CURLOPT_RESOLVE — the connection goes to
     * that IP while SNI, the Host header and certificate verification still
     * use the hostname, so TLS is verified exactly as without the override.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when $ip is not a valid IP address
     */
    private function fpmHttpOptions(string $url, ?string $ip): array
    {
        if ($ip === null || $ip === '') {
            return [];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("VAULT_DOCTOR_RESOLVE_IP is not a valid IP address: '{$ip}'.");
        }

        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException("Cannot pin the FPM request to {$ip}: no host in '{$url}'.");
        }

        $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $address = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$ip}]" : $ip;

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]]];
    }
}
