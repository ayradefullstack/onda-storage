<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Consultation\GenerateDerivative;
use App\Infrastructure\Render\PreviewFamilyResolver;
use App\Models\MediaConsultation;
use App\Models\MediaFile;
use Throwable;

/**
 * Builds the consultation derivatives (page images, sheet JSON, web video...)
 * for one file. Sits after GenerateVariants and before RecordDeposit.
 *
 * Runs AFTER the deposit is `ready` (dispatched by CleanupTemp, the chain's
 * last step), on the `previews` queue, so a slow encode never delays a
 * deposit. It decrypts the original into its OWN scratch directory.
 *
 * It shares PipelineJob's shape (queue, uniqueness, tries, backoff) but
 * OVERRIDES failed(): PipelineJob::failed() marks the DEPOSIT failed, and a
 * preview must never do that. `GenerateDerivative`
 * catches every Throwable and records `failed` on the consultation row, so
 * `handle()` returns normally and `Bus::chain()` keeps going. `failed()` here
 * covers the one case a catch cannot — the worker being killed at the
 * timeout — and also only touches the consultation row.
 *
 * `$timeout` stays below the previews connection's `retry_after`;
 * `ShouldBeUnique` on the media file uuid means repeated opens never queue
 * duplicates.
 */
final class GenerateConsultationDerivative extends PipelineJob
{
    /** Overwritten from vault.consult.job_timeout in the constructor. */
    public int $timeout = 6600;

    public function __construct(
        string $mediaFileUuid,
        public readonly bool $force = false,
    ) {
        parent::__construct($mediaFileUuid);

        // Its own queue AND its own connection: the `previews` connection has
        // a retry_after (7200 s) long enough for a full-length encode, while
        // `database` keeps 3600 s for the upload pipeline. The timeout is
        // always strictly below that retry_after.
        $this->onConnection('previews')->onQueue('previews');
        $this->timeout = (int) config('vault.consult.job_timeout');
    }

    public function handle(GenerateDerivative $generate): void
    {
        $mediaFile = MediaFile::where('uuid', $this->mediaFileUuid)->first();

        if ($mediaFile === null) {
            return;
        }

        $generate->handle($mediaFile, $this->force);
    }

    public function failed(Throwable $exception): void
    {
        $mediaFile = MediaFile::where('uuid', $this->mediaFileUuid)->first();

        if ($mediaFile === null) {
            return;
        }

        $consultation = MediaConsultation::forFile($mediaFile);
        $consultation->family = $consultation->family ?: app(PreviewFamilyResolver::class)->resolve($mediaFile)->value;
        $consultation->status = MediaConsultation::FAILED;
        $consultation->reason = 'render_timeout';
        $consultation->save();
    }
}
