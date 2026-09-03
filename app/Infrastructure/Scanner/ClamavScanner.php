<?php

declare(strict_types=1);

namespace App\Infrastructure\Scanner;

use App\Domain\Vault\Contracts\Scanner;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Shells out to `clamdscan --fdpass` — `--fdpass` passes the file descriptor
 * to the clamd daemon directly rather than a path, which matters when
 * clamd runs as a different user than PHP-FPM and wouldn't otherwise be
 * able to read a `0600` file under the vault's temp workspace.
 *
 * clamdscan exit codes: 0 = clean, 1 = infected, 2 = scan error (not a
 * verdict — must not be treated as either clean or infected).
 */
final class ClamavScanner implements Scanner
{
    public function __construct(
        private readonly string $binary,
        private readonly int $timeoutSeconds,
    ) {}

    public function scan(string $absolutePath): bool
    {
        $result = Process::timeout($this->timeoutSeconds)->run([$this->binary, '--fdpass', $absolutePath]);

        return match ($result->exitCode()) {
            0 => true,
            1 => false,
            default => throw new RuntimeException(
                "clamdscan exited with code {$result->exitCode()} scanning [{$absolutePath}]: ".trim($result->errorOutput().$result->output())
            ),
        };
    }
}
