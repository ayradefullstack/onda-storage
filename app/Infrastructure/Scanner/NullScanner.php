<?php

declare(strict_types=1);

namespace App\Infrastructure\Scanner;

use App\Domain\Vault\Contracts\Scanner;
use Illuminate\Support\Facades\Log;

/**
 * ClamAV isn't practical on Windows (CLAUDE.md) — the local `SCAN_DRIVER`.
 * Always reports clean, logged at debug level so a local run is visibly
 * distinguishable from a real scan in the logs.
 */
final class NullScanner implements Scanner
{
    public function scan(string $absolutePath): bool
    {
        Log::debug('NullScanner: skipping malware scan (SCAN_DRIVER=null).', ['path' => $absolutePath]);

        return true;
    }
}
