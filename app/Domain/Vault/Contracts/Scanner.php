<?php

declare(strict_types=1);

namespace App\Domain\Vault\Contracts;

/**
 * Scans a decrypted file for malware before it's marked ready. `NullScanner`
 * locally (ClamAV isn't practical on Windows), `ClamavScanner` in production.
 */
interface Scanner
{
    public function scan(string $absolutePath): bool;
}
