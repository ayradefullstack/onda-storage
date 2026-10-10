<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Media\TransitionMediaFileStatus;
use App\Domain\Access\AccessLogger;
use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\VaultConsistency;
use Illuminate\Console\Command;

/**
 * One-off repair for rows whose vault bytes are gone (the dedup race that
 * `DeduplicateFile` used to have). It deletes nothing: it marks every row the
 * doctor reports as missing bytes `failed`, and writes one `bytes_missing`
 * ledger row per file so the loss is on record. Dry-run by default.
 */
final class VaultMarkMissingBytesCommand extends Command
{
    protected $signature = 'vault:mark-missing-bytes
        {--dry-run : List what would change (this is the default)}
        {--apply : Actually mark the rows failed and write the audit rows}';

    protected $description = 'Mark media files whose vault bytes are missing as failed (reason bytes_missing). Dry-run unless --apply.';

    public function handle(VaultConsistency $consistency, TransitionMediaFileStatus $transition): int
    {
        $apply = (bool) $this->option('apply') && ! $this->option('dry-run');

        $rows = $consistency->missing()
            ->reject(fn (array $m): bool => $m['file']->status === MediaFileStatus::FAILED);

        if ($rows->isEmpty()) {
            $this->info('No row with missing bytes needs marking.');

            return self::SUCCESS;
        }

        $this->table(
            ['uuid', 'status', 'oeuvre', 'bin', 'mac', 'path'],
            $rows->map(fn (array $m): array => [
                $m['file']->uuid, $m['file']->status, $m['file']->oeuvre_id,
                $m['bin'] ? 'ok' : 'MISSING', $m['mac'] ? 'ok' : 'MISSING', $m['file']->path,
            ])->all(),
        );

        if (! $apply) {
            $this->warn("Dry run: {$rows->count()} row(s) would be marked failed (bytes_missing). Re-run with --apply.");

            return self::SUCCESS;
        }

        foreach ($rows as $m) {
            AccessLogger::record($m['file'], null, 'bytes_missing', 'system', null);
            $transition->handle($m['file'], MediaFileStatus::FAILED);
        }

        $this->info("Marked {$rows->count()} row(s) failed (bytes_missing). Nothing was deleted.");

        return self::SUCCESS;
    }
}
