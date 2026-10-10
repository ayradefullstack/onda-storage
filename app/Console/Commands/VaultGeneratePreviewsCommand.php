<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Render\PreviewFamily;
use App\Infrastructure\Render\PreviewFamilyResolver;
use App\Jobs\GenerateConsultationDerivative;
use App\Models\MediaConsultation;
use App\Models\MediaFile;
use Illuminate\Console\Command;

/**
 * Backfill for files deposited before the consultation feature (or whose
 * derivative failed). Queues one `GenerateConsultationDerivative` per file on
 * the `media` queue; that job is `ShouldBeUnique`, so running this twice never
 * queues duplicates. Never touches an original.
 *
 * `--force` regenerates: the new assets are stored first (fresh random
 * nonces) and the old ones are deleted only after that succeeded.
 */
final class VaultGeneratePreviewsCommand extends Command
{
    protected $signature = 'vault:generate-previews
        {--uuid= : Only this media file uuid}
        {--all : Every stored (ready, unpurged) file}
        {--only-missing : Skip files that already have a ready consultation}
        {--family= : Only this preview family (pdf, document, presentation, spreadsheet, csv, image, text, video, audio, archive, other)}
        {--force : Regenerate even when a ready consultation exists}';

    protected $description = 'Queue consultation derivatives (page images, sheet JSON, web video...) for deposited files.';

    public function handle(PreviewFamilyResolver $resolver): int
    {
        $uuid = (string) $this->option('uuid');
        $all = (bool) $this->option('all');

        if ($uuid === '' && ! $all) {
            $this->error('Pass --uuid=<media file uuid> or --all.');

            return self::FAILURE;
        }

        $family = null;

        if ((string) $this->option('family') !== '') {
            $family = PreviewFamily::tryFrom((string) $this->option('family'));

            if ($family === null) {
                $this->error('Unknown --family. Use one of: '.implode(', ', array_column(PreviewFamily::cases(), 'value')).'.');

                return self::FAILURE;
            }
        }

        $query = MediaFile::query()->stored();

        if ($uuid !== '') {
            $query->where('uuid', $uuid);
        }

        $queued = 0;
        $skipped = 0;

        foreach ($query->orderBy('id')->cursor() as $mediaFile) {
            if ($family !== null && $resolver->resolve($mediaFile) !== $family) {
                continue;
            }

            if ($this->option('only-missing') && ! $this->option('force')
                && MediaConsultation::where('media_file_id', $mediaFile->id)->where('status', MediaConsultation::READY)->exists()) {
                $skipped++;

                continue;
            }

            GenerateConsultationDerivative::dispatch($mediaFile->uuid, (bool) $this->option('force'));
            $queued++;
        }

        $this->info("Queued {$queued} file(s) on the 'media' queue; skipped {$skipped}.");

        return self::SUCCESS;
    }
}
