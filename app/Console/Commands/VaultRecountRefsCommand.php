<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Deposit\VaultConsistency;
use Illuminate\Console\Command;

/**
 * `media_files.ref_count` is informational (no deletion decision reads it —
 * see VaultBytesReleaser). Older dedup code incremented it and let it drift;
 * this sets it back to the real number of non-purged rows sharing each path.
 * Dry-run unless --apply.
 */
final class VaultRecountRefsCommand extends Command
{
    protected $signature = 'vault:recount-refs
        {--apply : Write the corrected counts (default is a dry run)}';

    protected $description = 'Fix media_files.ref_count so it equals the number of rows sharing the path. Dry-run unless --apply.';

    public function handle(VaultConsistency $consistency): int
    {
        $mismatches = $consistency->refCountMismatches();

        if ($mismatches->isEmpty()) {
            $this->info('Every ref_count already matches the real number of referencing rows.');

            return self::SUCCESS;
        }

        $this->table(
            ['uuid', 'status', 'ref_count', 'actual', 'path'],
            $mismatches->map(fn (array $m): array => [
                $m['file']->uuid, $m['file']->status, $m['file']->ref_count, $m['actual'], $m['file']->path,
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->warn("Dry run: {$mismatches->count()} row(s) would change. Re-run with --apply.");

            return self::SUCCESS;
        }

        $this->info('Corrected '.$consistency->recountRefs().' ref_count value(s). No bytes were touched.');

        return self::SUCCESS;
    }
}
