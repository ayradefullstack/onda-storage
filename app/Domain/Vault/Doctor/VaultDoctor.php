<?php

declare(strict_types=1);

namespace App\Domain\Vault\Doctor;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Audits the current PHP process (CLI or FPM) for ONDA vault readiness.
 * Every check is self-contained and never throws — an absent binary, path,
 * or config value is a reportable result, not an exception.
 */
final class VaultDoctor
{
    /**
     * @return Collection<int, CheckResult>
     */
    public function run(): Collection
    {
        return collect([
            ...$this->phpCoreChecks(),
            ...$this->uploadLimitChecks(),
            ...$this->disableFunctionsCheck(),
            ...$this->externalBinaryChecks(),
            ...$this->storageChecks(),
            ...$this->masterKeyChecks(),
            ...$this->databaseAndQueueChecks(),
            ...$this->redisCheck(),
            ...$this->assetBuildChecks(),
        ]);
    }

    /**
     * @return list<CheckResult>
     */
    private function phpCoreChecks(): array
    {
        $results = [];

        $results[] = new CheckResult(
            'php.int_size',
            '64-bit PHP',
            PHP_INT_SIZE === 8 ? CheckResult::PASS : CheckResult::FAIL,
            PHP_INT_SIZE.'-byte int',
            PHP_INT_SIZE === 8
                ? 'PHP is 64-bit.'
                : '32-bit PHP cannot seek beyond 2 GB — vault files up to 5 GB will corrupt.',
        );

        $versionOk = version_compare(PHP_VERSION, '8.4.0', '>=');
        $results[] = new CheckResult(
            'php.version',
            'PHP version',
            $versionOk ? CheckResult::PASS : CheckResult::FAIL,
            PHP_VERSION,
            $versionOk ? 'Meets the minimum of 8.4.' : 'Below the required 8.4.',
        );

        $results[] = new CheckResult('php.sapi', 'SAPI', CheckResult::PASS, PHP_SAPI, 'Informational.');

        $iniPath = php_ini_loaded_file();
        $results[] = new CheckResult(
            'php.ini_path',
            'Loaded php.ini',
            $iniPath !== false ? CheckResult::PASS : CheckResult::WARN,
            $iniPath !== false ? $iniPath : 'none loaded',
            $iniPath !== false
                ? 'Edit this file to change '.PHP_SAPI.' settings.'
                : 'No php.ini was loaded for this SAPI.',
        );

        foreach (['openssl', 'fileinfo', 'sodium', 'pcntl'] as $ext) {
            $loaded = extension_loaded($ext);

            if ($ext === 'pcntl' && PHP_OS_FAMILY === 'Windows') {
                $results[] = new CheckResult(
                    "ext.$ext",
                    "Extension: $ext",
                    CheckResult::PASS,
                    'not available',
                    'pcntl does not exist on Windows — expected, informational only.',
                );

                continue;
            }

            $results[] = new CheckResult(
                "ext.$ext",
                "Extension: $ext",
                $loaded ? CheckResult::PASS : ($ext === 'pcntl' ? CheckResult::WARN : CheckResult::FAIL),
                $loaded ? 'loaded' : 'missing',
                $loaded ? 'Loaded.' : "Required extension $ext is missing.",
            );
        }

        $cipherOk = in_array('aes-256-ctr', openssl_get_cipher_methods(), true);
        $results[] = new CheckResult(
            'openssl.aes_256_ctr',
            'aes-256-ctr cipher',
            $cipherOk ? CheckResult::PASS : CheckResult::FAIL,
            $cipherOk ? 'available' : 'unavailable',
            $cipherOk
                ? 'Available for envelope encryption.'
                : 'aes-256-ctr is required and not available in this OpenSSL build.',
        );

        $results[] = $this->throughputCheck();

        return $results;
    }

    private function throughputCheck(): CheckResult
    {
        $mib = 64;
        $key = random_bytes(32);
        $iv = random_bytes(16);
        $data = random_bytes($mib * 1024 * 1024);

        $start = hrtime(true);
        openssl_encrypt($data, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $mibPerSec = $elapsed > 0 ? round($mib / $elapsed, 1) : 0.0;
        $ok = $mibPerSec >= 200;

        return new CheckResult(
            'openssl.throughput',
            'AES-256-CTR throughput',
            $ok ? CheckResult::PASS : CheckResult::WARN,
            "{$mibPerSec} MiB/s",
            $ok
                ? 'Likely AES-NI accelerated.'
                : 'Below 200 MiB/s — likely no AES-NI; this becomes the pipeline bottleneck.',
        );
    }

    /**
     * @return list<CheckResult>
     */
    private function uploadLimitChecks(): array
    {
        $isCli = PHP_SAPI === 'cli';
        $results = [];

        $postMax = ini_get('post_max_size');
        $postMaxBytes = $this->iniToBytes($postMax);

        if ($isCli) {
            $status = CheckResult::PASS;
            $rationale = 'CLI value — informational only. Run with --fpm to see the value that actually governs uploads.';
        } elseif ($postMaxBytes < 32 * 1024 * 1024) {
            $status = CheckResult::WARN;
            $rationale = 'Below 32M — chunk uploads will be rejected with a 413.';
        } elseif ($postMaxBytes > 256 * 1024 * 1024) {
            $status = CheckResult::WARN;
            $rationale = 'Above 256M — limits are too loose; single-request violations of the chunking architecture will go unnoticed.';
        } else {
            $status = CheckResult::PASS;
            $rationale = 'FPM value governs chunk uploads.';
        }

        $results[] = new CheckResult('ini.post_max_size', 'post_max_size', $status, (string) $postMax, $rationale, sapiSensitive: true);

        foreach (['upload_max_filesize', 'memory_limit', 'max_execution_time', 'max_input_time'] as $key) {
            $value = ini_get($key);
            $results[] = new CheckResult(
                "ini.$key",
                $key,
                CheckResult::PASS,
                $value === false ? 'unavailable' : $value,
                $isCli
                    ? 'CLI value — informational only; the FPM value governs runtime behaviour.'
                    : 'FPM value.',
                sapiSensitive: true,
            );
        }

        return $results;
    }

    private function iniToBytes(string|false $value): int
    {
        if ($value === false || $value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }

    /**
     * @return list<CheckResult>
     */
    private function disableFunctionsCheck(): array
    {
        $fns = ['exec', 'shell_exec', 'proc_open'];
        $callable = array_filter($fns, fn (string $fn): bool => function_exists($fn));

        $status = $callable === [] ? CheckResult::FAIL : CheckResult::PASS;
        $value = implode(', ', array_map(
            fn (string $fn): string => $fn.'='.(function_exists($fn) ? 'callable' : 'disabled'),
            $fns,
        ));

        return [new CheckResult(
            'disable_functions',
            'Process execution functions',
            $status,
            $value,
            $status === CheckResult::FAIL
                ? 'exec/shell_exec/proc_open are all disabled — ffmpeg and ClamAV cannot be invoked.'
                : 'At least one process-execution function is callable.',
            sapiSensitive: true,
        )];
    }

    /**
     * @return list<CheckResult>
     */
    private function externalBinaryChecks(): array
    {
        $results = [];

        $binaries = [
            'ffmpeg' => 'MEDIA_PROBE_DRIVER (falls back to NullProbe)',
            'ffprobe' => 'MEDIA_PROBE_DRIVER (falls back to NullProbe)',
            'clamdscan' => 'SCAN_DRIVER (must stay null locally; falls back to NullScanner)',
        ];

        foreach ($binaries as $bin => $configKey) {
            $path = $this->resolveBinary($bin);

            if ($path === null) {
                $results[] = new CheckResult(
                    "bin.$bin",
                    "Binary: $bin",
                    CheckResult::WARN,
                    'not found',
                    "Absent — disable the dependent feature via $configKey.",
                );

                continue;
            }

            $results[] = new CheckResult(
                "bin.$bin",
                "Binary: $bin",
                CheckResult::PASS,
                $path.' ('.$this->binaryVersion($path).')',
                'Resolved and callable.',
            );
        }

        return $results;
    }

    private function resolveBinary(string $bin): ?string
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $cmd = PHP_OS_FAMILY === 'Windows'
            ? "where $bin 2>NUL"
            : "which $bin 2>/dev/null";

        $out = @shell_exec($cmd);

        if (! $out) {
            return null;
        }

        $first = trim(explode("\n", trim($out))[0]);

        return $first !== '' ? $first : null;
    }

    private function binaryVersion(string $path): string
    {
        $out = @shell_exec('"'.$path.'" -version 2>&1');

        if (! $out) {
            return 'unknown version';
        }

        return trim(explode("\n", trim($out))[0]);
    }

    /**
     * @return list<CheckResult>
     */
    private function storageChecks(): array
    {
        $disks = ['vault', 'incoming', 'work', 'variants'];
        $results = [];
        $devices = [];

        foreach ($disks as $disk) {
            $root = config("filesystems.disks.$disk.root");

            if (! is_string($root) || $root === '') {
                $results[] = new CheckResult(
                    "storage.$disk.configured",
                    "Disk '$disk' configured",
                    CheckResult::FAIL,
                    'not configured',
                    "config/filesystems.php has no '$disk' disk with a root path yet.",
                );

                continue;
            }

            $exists = is_dir($root);
            $results[] = new CheckResult(
                "storage.$disk.exists",
                "Disk '$disk' root exists",
                $exists ? CheckResult::PASS : CheckResult::FAIL,
                $root,
                $exists ? 'Directory exists.' : 'Directory does not exist.',
            );

            if (! $exists) {
                continue;
            }

            $resolved = realpath($root) ?: $root;

            $writable = is_writable($resolved);
            $results[] = new CheckResult(
                "storage.$disk.writable",
                "Disk '$disk' writable",
                $writable ? CheckResult::PASS : CheckResult::FAIL,
                $resolved,
                $writable ? 'Writable.' : 'Not writable by the current process user.',
            );

            $insideRepo = str_starts_with(
                str_replace('\\', '/', $resolved),
                str_replace('\\', '/', base_path()),
            );
            $results[] = new CheckResult(
                "storage.$disk.outside_repo",
                "Disk '$disk' outside repo",
                $insideRepo ? CheckResult::FAIL : CheckResult::PASS,
                $resolved,
                $insideRepo
                    ? 'Root is inside the project directory — it must be fully separate.'
                    : 'Correctly outside the repo.',
            );

            $openBasedir = ini_get('open_basedir');
            if ($openBasedir) {
                $allowed = collect(explode(PATH_SEPARATOR, $openBasedir))
                    ->filter(fn (string $p): bool => $p !== '')
                    ->contains(fn (string $p): bool => str_starts_with(
                        str_replace('\\', '/', $resolved),
                        str_replace('\\', '/', rtrim($p, '/*')),
                    ));

                $results[] = new CheckResult(
                    "storage.$disk.open_basedir",
                    "Disk '$disk' within open_basedir",
                    $allowed ? CheckResult::PASS : CheckResult::FAIL,
                    $openBasedir,
                    $allowed ? 'Within the allowed paths.' : 'Outside open_basedir — PHP will refuse to access it.',
                    sapiSensitive: true,
                );
            }

            $free = @disk_free_space($resolved);
            $results[] = new CheckResult(
                "storage.$disk.free_space",
                "Disk '$disk' free space",
                CheckResult::PASS,
                $free !== false ? round($free / 1024 ** 3, 1).' GiB' : 'unknown',
                'Informational.',
            );

            $devices[$disk] = $this->deviceIdentity($resolved);

            if (PHP_OS_FAMILY === 'Windows') {
                if (stripos($resolved, 'onedrive') !== false) {
                    $results[] = new CheckResult(
                        "storage.$disk.onedrive",
                        "Disk '$disk' OneDrive check",
                        CheckResult::WARN,
                        $resolved,
                        'Path is inside a OneDrive-synced location — sync can corrupt writes in progress.',
                    );
                }

                $systemDrive = getenv('SystemDrive') ?: 'C:';
                if (strtoupper(substr($resolved, 0, 2)) === strtoupper($systemDrive)) {
                    $results[] = new CheckResult(
                        "storage.$disk.system_drive",
                        "Disk '$disk' system drive check",
                        CheckResult::WARN,
                        $resolved,
                        "Root is on the system drive ($systemDrive) — prefer a dedicated data drive.",
                    );
                }
            }

            $results[] = $this->offsetProofCheck($disk, $resolved);
        }

        if (count($devices) >= 2) {
            $results[] = $this->partitionResult($devices);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $devices  disk name => device/drive identity
     */
    private function partitionResult(array $devices): CheckResult
    {
        $unique = array_unique($devices);
        $same = count($unique) === 1;

        $summary = implode(', ', array_map(
            fn (string $disk, string $dev): string => "$disk=$dev",
            array_keys($devices),
            $devices,
        ));

        return new CheckResult(
            'storage.partition_identity',
            'All vault disks share one partition',
            $same ? CheckResult::PASS : CheckResult::FAIL,
            $summary,
            $same
                ? 'rename() will be instant across disks.'
                : 'Disks span multiple devices/drives — complete() would silently degrade to a full copy.',
        );
    }

    private function deviceIdentity(string $path): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return strtoupper(substr($path, 0, 2));
        }

        $stat = @stat($path);

        return $stat !== false ? (string) $stat['dev'] : 'unknown';
    }

    private function offsetProofCheck(string $disk, string $root): CheckResult
    {
        $key = "storage.$disk.offset_proof";
        $label = "Disk '$disk' offset write/read proof";
        $file = rtrim($root, '/\\').DIRECTORY_SEPARATOR.'vault-doctor-'.bin2hex(random_bytes(4)).'.tmp';

        $fh = @fopen($file, 'c+b');

        if ($fh === false) {
            return new CheckResult($key, $label, CheckResult::FAIL, $root, 'Could not open a test file for writing.');
        }

        try {
            $total = 64 * 1024 * 1024;
            $offset = 32 * 1024 * 1024;
            $chunkSize = 1024 * 1024;

            if (! ftruncate($fh, $total)) {
                return new CheckResult($key, $label, CheckResult::FAIL, $root, 'ftruncate() to 64 MiB failed.');
            }

            $pattern = random_bytes($chunkSize);

            if (fseek($fh, $offset) !== 0) {
                return new CheckResult($key, $label, CheckResult::FAIL, $root, 'fseek() to the 32 MiB offset failed.');
            }

            fwrite($fh, $pattern);
            fflush($fh);

            if (fseek($fh, $offset) !== 0) {
                return new CheckResult($key, $label, CheckResult::FAIL, $root, 'fseek() for readback failed.');
            }

            $readBack = fread($fh, $chunkSize);

            if ($readBack !== $pattern) {
                return new CheckResult(
                    $key,
                    $label,
                    CheckResult::FAIL,
                    $root,
                    'Readback did not match the written pattern — offset writes are not reliable here.',
                );
            }

            clearstatcache(true, $file);
            $size = filesize($file);

            if ($size !== $total) {
                return new CheckResult($key, $label, CheckResult::FAIL, $root, "File size after write is $size bytes, expected $total.");
            }

            return new CheckResult(
                $key,
                $label,
                CheckResult::PASS,
                $root,
                'Offset write/read/size round-trip verified — the core chunk-write mechanism works here.',
            );
        } finally {
            fclose($fh);
            @unlink($file);
        }
    }

    /**
     * @return list<CheckResult>
     */
    private function masterKeyChecks(): array
    {
        $path = config('vault.master_key_path');

        if (! is_string($path) || $path === '') {
            return [new CheckResult(
                'vault_key.set',
                'VAULT_MASTER_KEY_PATH',
                CheckResult::FAIL,
                'not set',
                'No master key path configured.',
            )];
        }

        $results = [new CheckResult('vault_key.set', 'VAULT_MASTER_KEY_PATH', CheckResult::PASS, $path, 'Set.')];

        if (! is_file($path)) {
            $results[] = new CheckResult(
                'vault_key.exists',
                'Master key file exists',
                CheckResult::FAIL,
                $path,
                'File does not exist at the configured path.',
            );

            return $results;
        }

        $readable = is_readable($path);
        $results[] = new CheckResult(
            'vault_key.readable',
            'Master key file readable',
            $readable ? CheckResult::PASS : CheckResult::FAIL,
            $path,
            $readable ? 'Readable.' : 'Not readable by the current process user.',
        );

        if ($readable) {
            $size = filesize($path);
            $sizeOk = $size !== false && $size >= 32;

            $results[] = new CheckResult(
                'vault_key.size',
                'Master key size',
                $sizeOk ? CheckResult::PASS : CheckResult::FAIL,
                $size !== false ? "$size bytes" : 'unknown',
                $sizeOk ? 'At least 32 bytes.' : 'Must be at least 32 bytes.',
            );
        }

        $resolved = realpath($path) ?: $path;
        $inRepo = str_starts_with(
            str_replace('\\', '/', $resolved),
            str_replace('\\', '/', base_path()),
        );

        $results[] = new CheckResult(
            'vault_key.outside_repo',
            'Master key outside repo',
            $inRepo ? CheckResult::FAIL : CheckResult::PASS,
            'hidden',
            $inRepo
                ? 'Key file is inside the project directory — it must live outside it and outside backups.'
                : 'Correctly outside the repo.',
        );

        if (PHP_OS_FAMILY !== 'Windows') {
            $perms = fileperms($path) & 0777;
            $tooOpen = ($perms & 0077) !== 0;

            $results[] = new CheckResult(
                'vault_key.permissions',
                'Master key permissions',
                $tooOpen ? CheckResult::WARN : CheckResult::PASS,
                sprintf('%04o', $perms),
                $tooOpen ? 'Looser than 0600.' : '0600 or tighter.',
            );
        }

        return $results;
    }

    /**
     * @return list<CheckResult>
     */
    private function databaseAndQueueChecks(): array
    {
        $results = [];

        try {
            DB::connection()->getPdo();
            $results[] = new CheckResult(
                'db.connection',
                'Database connection',
                CheckResult::PASS,
                DB::connection()->getDriverName(),
                'Connected successfully.',
            );
        } catch (Throwable $e) {
            $results[] = new CheckResult('db.connection', 'Database connection', CheckResult::FAIL, 'unreachable', $e->getMessage());
        }

        $retryAfter = config('queue.connections.database.retry_after');
        $retryOk = is_int($retryAfter) && $retryAfter >= 3600;

        $results[] = new CheckResult(
            'queue.retry_after',
            'queue.connections.database.retry_after',
            $retryOk ? CheckResult::PASS : CheckResult::FAIL,
            (string) $retryAfter,
            $retryOk
                ? 'At least 3600s — a long-running job will not be re-dispatched while still executing.'
                : 'Below 3600s — a running 5 GB hash or ffmpeg job would be re-dispatched mid-flight.',
        );

        try {
            $pending = DB::table('jobs')->count();
            $results[] = new CheckResult('queue.pending', 'Pending jobs', CheckResult::PASS, (string) $pending, 'Informational.');
        } catch (Throwable $e) {
            $results[] = new CheckResult('queue.pending', 'Pending jobs', CheckResult::WARN, 'unknown', "'jobs' table not reachable: ".$e->getMessage());
        }

        try {
            $failed = DB::table('failed_jobs')->count();
            $results[] = new CheckResult(
                'queue.failed',
                'Failed jobs',
                $failed > 0 ? CheckResult::WARN : CheckResult::PASS,
                (string) $failed,
                $failed > 0 ? 'There are failed jobs to review.' : 'None.',
            );
        } catch (Throwable $e) {
            $results[] = new CheckResult('queue.failed', 'Failed jobs', CheckResult::WARN, 'unknown', "'failed_jobs' table not reachable: ".$e->getMessage());
        }

        $workerRunning = $this->isQueueWorkerRunning();
        $results[] = new CheckResult(
            'queue.worker',
            'Queue worker running',
            $workerRunning === true ? CheckResult::PASS : CheckResult::WARN,
            $workerRunning === null ? 'unknown' : ($workerRunning ? 'running' : 'not detected'),
            'Herd does not manage the queue worker — start it manually with `php artisan queue:work`.',
        );

        return $results;
    }

    private function isQueueWorkerRunning(): ?bool
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $out = @shell_exec('wmic process where "CommandLine like \'%queue:work%\'" get CommandLine 2>NUL');

            return is_string($out) ? str_contains($out, 'queue:work') : null;
        }

        $out = @shell_exec('ps aux 2>/dev/null | grep "queue:work" | grep -v grep');

        return is_string($out) ? trim($out) !== '' : null;
    }

    /**
     * A stale `public/build` bundle is invisible from the browser — the app
     * looks fine and behaves as if committed source fixes do not exist. This
     * exact trap (a chunk-upload fix sitting in source while a pre-fix
     * bundle kept being served) survived two manual test cycles before it
     * was found by reading logs, not by anything in this tool. It belongs
     * here so it surfaces on every run instead of by accident.
     *
     * @return list<CheckResult>
     */
    private function assetBuildChecks(): array
    {
        return [$this->assetSourceCheck(
            public_path('hot'),
            public_path('build/manifest.json'),
            resource_path('js'),
            app()->environment(),
        )];
    }

    private function assetSourceCheck(
        string $hotFile,
        string $manifestPath,
        string $jsSourceDir,
        string $environment,
    ): CheckResult {
        $key = 'assets.source';
        $label = 'Frontend asset source';

        if (is_file($hotFile)) {
            return new CheckResult(
                $key,
                $label,
                CheckResult::PASS,
                'Vite dev server (hot)',
                'A Vite dev server is active — the browser receives fresh, unbundled assets.',
            );
        }

        if (! is_file($manifestPath)) {
            return new CheckResult(
                $key,
                $label,
                CheckResult::FAIL,
                'no build found',
                "Neither a Vite dev server (public/hot) nor a built manifest ($manifestPath) exists — run `npm run dev` or `npm run build`.",
            );
        }

        if ($environment !== 'local') {
            return new CheckResult(
                $key,
                $label,
                CheckResult::PASS,
                "built assets ($environment)",
                'Serving the compiled build — expected outside local development.',
            );
        }

        $buildTime = filemtime($manifestPath);
        $newestSource = $this->newestMtimeUnder($jsSourceDir);

        if ($buildTime === false || $newestSource === false || $newestSource <= $buildTime) {
            return new CheckResult(
                $key,
                $label,
                CheckResult::PASS,
                'built assets (public/build)',
                'Serving the compiled build; it is at least as new as resources/js.',
            );
        }

        $ageMinutes = max(1, (int) round(($newestSource - $buildTime) / 60));

        return new CheckResult(
            $key,
            $label,
            CheckResult::WARN,
            'built assets (public/build) — stale',
            "resources/js has a file about {$ageMinutes} minute(s) newer than public/build/manifest.json. ".
                'The browser is serving a bundle compiled before your latest source changes — run `npm run build`, '.
                'or start `npm run dev` for local work. Serving built assets locally is legitimate; this only warns when they are behind.',
        );
    }

    private function newestMtimeUnder(string $dir): int|false
    {
        if (! is_dir($dir)) {
            return false;
        }

        $newest = false;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $mtime = $file->getMTime();

            if ($newest === false || $mtime > $newest) {
                $newest = $mtime;
            }
        }

        return $newest;
    }

    /**
     * @return list<CheckResult>
     */
    private function redisCheck(): array
    {
        try {
            Redis::connection()->ping();

            return [new CheckResult(
                'redis',
                'Redis',
                CheckResult::PASS,
                'available',
                'Redis responded to PING. Optional — not a hard dependency.',
            )];
        } catch (Throwable $e) {
            return [new CheckResult(
                'redis',
                'Redis',
                CheckResult::WARN,
                'unavailable',
                'Redis is not reachable. This is fine — Redis is optional by design.',
            )];
        }
    }
}
