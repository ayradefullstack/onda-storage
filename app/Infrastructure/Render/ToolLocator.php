<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Finds an external binary: the configured absolute path if it is a file,
 * else a PATH lookup of the configured name, else a few well-known install
 * locations (a winget/installer binary is often not on the queue worker's
 * PATH). Null means "not installed" and the caller degrades to an
 * unsupported card — a missing tool never fails a deposit.
 */
final class ToolLocator
{
    /** @var array<string, list<string>> */
    private const WELL_KNOWN = [
        'soffice_binary' => [
            'C:/Program Files/LibreOffice/program/soffice.exe',
            'C:/Program Files (x86)/LibreOffice/program/soffice.exe',
            '/usr/bin/soffice',
            '/usr/local/bin/soffice',
            '/opt/libreoffice/program/soffice',
        ],
        'pdftoppm_binary' => [
            '/usr/bin/pdftoppm',
            '/usr/local/bin/pdftoppm',
        ],
        'ffmpeg_binary' => [],
    ];

    public function find(string $configKey): ?string
    {
        $configured = trim((string) config("vault.{$configKey}"));

        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        // An absolute path that does not exist is a misconfiguration, not a
        // hint: report "not installed" rather than silently using another
        // binary.
        if ($configured !== '' && $this->isAbsolute($configured)) {
            return null;
        }

        if ($configured !== '') {
            $found = (new ExecutableFinder)->find($configured);

            if ($found !== null) {
                return $found;
            }
        }

        foreach (self::WELL_KNOWN[$configKey] ?? [] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^([A-Za-z]:)?[\\\\/]~', $path) === 1;
    }
}
