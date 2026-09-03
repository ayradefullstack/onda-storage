<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * The chain launcher — and, deliberately, the exact class name
 * `App\Jobs\ProcessMediaFile` that `App\Actions\Upload\CompleteUpload`
 * (frozen this phase) already looks for:
 *
 *     $job = 'App\\Jobs\\ProcessMediaFile';
 *     if (class_exists($job)) { $job::dispatch($mediaFile->uuid)->onQueue('media'); }
 *
 * That guard has been checking for a class that didn't exist since P3 —
 * this class existing is what turns it on, with no change to the frozen
 * file. `chainJobs()` is `public static` so `VaultReprocessCommand` builds
 * the identical chain rather than a second, maybe-drifted copy of the job
 * list.
 */
final class ProcessMediaFile implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $mediaFileUuid,
    ) {}

    public function uniqueId(): string
    {
        return $this->mediaFileUuid;
    }

    public function handle(): void
    {
        Bus::chain(self::chainJobs($this->mediaFileUuid))
            ->onQueue('media')
            ->dispatch();
    }

    /**
     * @return list<PipelineJob>
     */
    public static function chainJobs(string $mediaFileUuid): array
    {
        return [
            new DecryptToTemp($mediaFileUuid),
            new ComputeContentHash($mediaFileUuid),
            new DeduplicateFile($mediaFileUuid),
            new ScanForMalware($mediaFileUuid),
            new ExtractMetadata($mediaFileUuid),
            new GenerateVariants($mediaFileUuid),
            new RecordDeposit($mediaFileUuid),
            new CleanupTemp($mediaFileUuid),
        ];
    }
}
