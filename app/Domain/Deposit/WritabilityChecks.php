<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Vault\Doctor\CheckResult;
use Illuminate\Support\Collection;
use Throwable;

/**
 * `vault:doctor` write probe. `is_writable()` reports permission bits, not
 * what the current OS user can really do (ACLs, a log file owned by another
 * user, a read-only mount), so each directory gets a real create-and-delete of
 * a temp file. A directory that fails is a FAIL carrying its path.
 */
final class WritabilityChecks
{
    /**
     * @return Collection<int, CheckResult>
     */
    public function run(): Collection
    {
        $targets = [
            'logs' => storage_path('logs'),
            'bootstrap_cache' => base_path('bootstrap/cache'),
        ];

        foreach (['vault', 'incoming', 'work', 'variants'] as $disk) {
            $targets[$disk] = (string) config("filesystems.disks.$disk.root");
        }

        $results = collect();

        foreach ($targets as $name => $dir) {
            $error = $this->probe($dir);

            $results->push(new CheckResult(
                "write.$name",
                "Write probe: $name",
                $error === null ? CheckResult::PASS : CheckResult::FAIL,
                $dir,
                $error === null
                    ? 'A temp file was created and deleted as the current OS user.'
                    : "Not writable as the current OS user ($error). Fix the owner or ACL of this directory.",
            ));
        }

        $log = storage_path('logs/laravel.log');

        if (is_file($log)) {
            $error = $this->probeAppend($log);

            $results->push(new CheckResult(
                'write.laravel_log',
                'Write probe: laravel.log',
                $error === null ? CheckResult::PASS : CheckResult::FAIL,
                $log,
                $error === null ? 'The log file can be opened for append.' : "The existing log file is not writable ($error).",
            ));
        }

        return $results;
    }

    private function probe(string $dir): ?string
    {
        if ($dir === '' || ! is_dir($dir)) {
            return 'directory missing';
        }

        $file = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'.doctor-probe-'.bin2hex(random_bytes(6));

        try {
            if (@file_put_contents($file, 'probe') === false) {
                return error_get_last()['message'] ?? 'write failed';
            }

            if (! @unlink($file)) {
                return 'created but could not be deleted';
            }
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function probeAppend(string $file): ?string
    {
        $handle = @fopen($file, 'ab');

        if ($handle === false) {
            return error_get_last()['message'] ?? 'Permission denied';
        }

        fclose($handle);

        return null;
    }
}
