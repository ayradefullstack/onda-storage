<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessMediaFile;
use App\Models\MediaFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

final class VaultReprocessCommand extends Command
{
    protected $signature = 'vault:reprocess {uuid : The media file uuid to re-run the pipeline for}';

    protected $description = 'Re-run the P5 post-upload processing chain for a single media file.';

    public function handle(): int
    {
        $uuid = (string) $this->argument('uuid');
        $mediaFile = MediaFile::withTrashed()->where('uuid', $uuid)->first();

        if ($mediaFile === null) {
            $this->error("No media file found with uuid [{$uuid}].");

            return self::FAILURE;
        }

        Bus::chain(ProcessMediaFile::chainJobs($uuid))->onQueue('media')->dispatch();

        $this->info("Reprocessing dispatched for [{$uuid}] on the 'media' queue.");

        return self::SUCCESS;
    }
}
