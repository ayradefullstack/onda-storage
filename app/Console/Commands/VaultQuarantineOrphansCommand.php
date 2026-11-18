<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deposit\VaultBytesReleaser;
use App\Domain\Deposit\VaultConsistency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Tidies vault files that no media_files row references (leftovers of old
 * test and e2e runs, abandoned finalisations).
 *
 * It NEVER deletes: unreferenced `.bin` / `.mac` files are MOVED, as a pair,
 * into `orphans/{date}/` next to the vault (same partition, so the move is a
 * rename), keeping their `xx/yy/` layout so a mistaken move is a rename back.
 * Before each file moves, the references are re-counted with the same logic
 * `VaultBytesReleaser` uses for deletion, so a row created since the scan
 * keeps its bytes. Dry-run unless --apply.
 */
final class VaultQuarantineOrphansCommand extends Command
{
    protected $signature = 'vault:quarantine-orphans
        {--apply : Actually move the files (default is a dry run)}
        {--older-than=7 : Only files last modified more than this many days ago}';

    protected $description = 'Move unreferenced vault files into orphans/{date}/ next to the vault. Never deletes. Dry-run unless --apply.';

    public function handle(VaultConsistency $consistency, VaultBytesReleaser $releaser): int
    {
        $days = max(0, (int) $this->option('older-than'));
        $apply = (bool) $this->option('apply');
        $vault = Storage::disk('vault');
        $vaultRoot = rtrim(str_replace('\\', '/', $vault->path('')), '/');
        $target = dirname($vaultRoot).'/orphans/'.now()->format('Y-m-d');
        $cutoff = time() - $days * 86400;

        $files = [];
        $bytes = 0;

        foreach ($consistency->orphans() as $path) {
            if ($vault->lastModified($path) > $cutoff) {
                continue;
            }

            $files[] = $path;
            $bytes += $vault->size($path);
        }

        if ($files === []) {
            $this->info("No unreferenced vault file older than {$days} day(s).");

            return self::SUCCESS;
        }

        $this->line(sprintf('%d file(s), %s, older than %d day(s)%s.', count($files), $this->human($bytes), $days, $apply ? '' : ' (dry run)'));

        if (! $apply) {
            $this->line("They would be moved to {$target}/ (layout preserved). Nothing was moved. Re-run with --apply.");

            return self::SUCCESS;
        }

        $moved = 0;
        $kept = 0;

        foreach ($files as $path) {
            // Re-checked right before the move, with the deletion logic's own count.
            if ($releaser->referenceCount('vault', $path) > 0) {
                $kept++;

                continue;
            }

            $destination = $target.'/'.$path;
            @mkdir(dirname($destination), 0700, true);

            if (@rename($vaultRoot.'/'.$path, $destination)) {
                $moved++;
            } else {
                $this->warn("Could not move {$path}; left in place.");
            }
        }

        $this->info("Moved {$moved} file(s) to {$target}/; kept {$kept} that became referenced. Nothing was deleted.");

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        return round($bytes / 1048576, 1).' MiB';
    }
}
