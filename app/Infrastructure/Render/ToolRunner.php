<?php

declare(strict_types=1);

namespace App\Infrastructure\Render;

use Symfony\Component\Process\Process;

/**
 * Runs an external converter on untrusted input. The command is always an
 * argument array (never a shell string) and there is a hard wall-clock
 * timeout; on expiry the WHOLE process tree is killed, because converters
 * like LibreOffice re-exec into a child (`soffice` -> `soffice.bin`) and
 * killing only the parent would leave the real worker running.
 */
final class ToolRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     * @return array{ok: bool, timedOut: bool, output: string}
     */
    public function run(array $command, int $timeoutSeconds, ?string $cwd = null, array $env = []): array
    {
        $process = new Process($command, $cwd, $env === [] ? null : $env, null, null);
        $process->start();

        $deadline = microtime(true) + $timeoutSeconds;

        while ($process->isRunning()) {
            if (microtime(true) >= $deadline) {
                $this->killTree($process);

                return ['ok' => false, 'timedOut' => true, 'output' => 'timed out'];
            }

            usleep(100_000);
        }

        return [
            'ok' => $process->getExitCode() === 0,
            'timedOut' => false,
            'output' => mb_substr(trim($process->getErrorOutput()), -500),
        ];
    }

    private function killTree(Process $process): void
    {
        $pid = $process->getPid();

        if ($pid !== null) {
            if (PHP_OS_FAMILY === 'Windows') {
                (new Process(['taskkill', '/PID', (string) $pid, '/T', '/F']))->run();
            } else {
                (new Process(['pkill', '-KILL', '-P', (string) $pid]))->run();
            }
        }

        $process->stop(0, defined('SIGKILL') ? SIGKILL : null);
    }
}
