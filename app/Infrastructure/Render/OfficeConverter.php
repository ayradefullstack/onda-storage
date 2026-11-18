<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use RuntimeException;

/**
 * LibreOffice headless -> PDF, for untrusted input.
 *
 * - Argument array, absolute binary from `vault.soffice_binary`.
 * - A per-job profile (`-env:UserInstallation`) inside the workspace: it
 *   avoids soffice's single-instance lock and isolates settings between jobs,
 *   so conversions need NOT be serialised with a Cache::lock. They only run on
 *   the `media` queue, whose worker count is the concurrency limit.
 * - Macros are never enabled (no `--enable-macros`; headless default is off).
 * - Hard timeout `vault.consult.convert_timeout`; the whole process tree is
 *   killed on expiry (see ToolRunner).
 * - The intermediate PDF lives only in the workspace.
 */
final class OfficeConverter
{
    public function __construct(
        private readonly ToolLocator $tools,
        private readonly ToolRunner $runner,
    ) {}

    public function available(): bool
    {
        return $this->tools->find('soffice_binary') !== null;
    }

    /**
     * @param  string  $input  staged copy (see stage()) named with the real extension
     * @return string absolute path of the produced PDF
     */
    public function toPdf(string $input, string $workspace): string
    {
        $soffice = $this->tools->find('soffice_binary');

        if ($soffice === null) {
            throw new RuntimeException('tool_missing:soffice');
        }

        $out = $workspace.DIRECTORY_SEPARATOR.'office-out';
        $profile = $workspace.DIRECTORY_SEPARATOR.'lo-profile';
        @mkdir($out, 0700, true);

        $run = $this->runner->run([
            $soffice,
            '--headless', '--norestore', '--nologo', '--nodefault', '--nolockcheck',
            '-env:UserInstallation=file:///'.ltrim(str_replace('\\', '/', $profile), '/'),
            '--convert-to', 'pdf',
            '--outdir', $out,
            $input,
        ], (int) config('vault.consult.convert_timeout'), $workspace);

        if ($run['timedOut']) {
            throw new RuntimeException('convert_timeout');
        }

        $pdfs = glob($out.DIRECTORY_SEPARATOR.'*.pdf') ?: [];

        if (! $run['ok'] || $pdfs === []) {
            throw new RuntimeException('convert_failed');
        }

        return $pdfs[0];
    }

    /**
     * soffice picks its import filter from the extension, and pipeline
     * scratch files are named `<uuid>.tmp`, so stage a copy named by the real
     * extension — inside the workspace, never anywhere else.
     */
    public function stage(string $sourcePath, string $workspace, string $extension): string
    {
        $input = $workspace.DIRECTORY_SEPARATOR.'input.'.$extension;

        if (! @copy($sourcePath, $input)) {
            throw new RuntimeException('stage_failed');
        }

        return $input;
    }
}
