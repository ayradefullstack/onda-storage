<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CollegeOeuvreFile;
use App\Support\FileFormats;
use Illuminate\Console\Command;

/**
 * Recomputes `college_oeuvre_files.mime_types` from `extensions` through
 * the format registry.
 *
 * The model's `saving` hook keeps the two columns in step whenever a row is
 * written. This command exists for the other direction of drift: the
 * registry itself changing — a new MIME alias added for an OpenXML format,
 * say — under rows that were perfectly correct when they were last saved
 * and are now missing that alias.
 *
 * Run it after any change to App\Support\FileFormats. It is on the
 * deployment checklist for exactly that reason.
 *
 * Idempotent, and reports what it would do with --dry-run before doing it.
 */
final class SyncMimeTypesCommand extends Command
{
    protected $signature = 'referentiel:sync-mime-types
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Recompute college_oeuvre_files.mime_types from the file-format registry.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $unsupported = [];

        // withTrashed: a retired requirement still governs the files already
        // deposited under it, so its MIME list must stay correct.
        CollegeOeuvreFile::withTrashed()
            ->with('registerTypeCollege:id,code_college')
            ->chunkById(200, function ($rows) use (&$changed, &$unsupported, $dryRun): void {
                foreach ($rows as $row) {
                    foreach ($row->extensions as $extension) {
                        if (! FileFormats::isSupported($extension)) {
                            $unsupported[] = sprintf(
                                '%s / %s / %s',
                                $row->registerTypeCollege->code_college ?? '?',
                                $row->document_key,
                                $extension,
                            );
                        }
                    }

                    $derived = FileFormats::mimeTypesFor($row->extensions);

                    if ($derived === $row->mime_types) {
                        continue;
                    }

                    $changed++;

                    $this->line(sprintf(
                        '  <fg=yellow>%s</> / %s',
                        $row->registerTypeCollege->code_college ?? '?',
                        $row->document_key,
                    ));
                    $this->line('      from: '.json_encode($row->mime_types));
                    $this->line('        to: '.json_encode($derived));

                    if (! $dryRun) {
                        // The saving hook derives it again from `extensions`;
                        // assigning here keeps the intent readable.
                        $row->mime_types = $derived;
                        $row->save();
                    }
                }
            });

        if ($unsupported !== []) {
            $this->newLine();
            $this->warn('Extensions present on a row but absent from the registry:');

            foreach (array_unique($unsupported) as $line) {
                $this->warn('  '.$line);
            }

            $this->warn('Those contribute no MIME types, so their slot accepts nothing for them.');
        }

        $this->newLine();
        $this->info($dryRun
            ? "{$changed} row(s) would change. Re-run without --dry-run to apply."
            : "{$changed} row(s) updated.");

        return self::SUCCESS;
    }
}
