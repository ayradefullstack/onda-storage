<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Media\MarkMediaFileFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Shared shape for every P5 pipeline job: dispatched to `media`, unique per
 * (job class, media file uuid), 2 tries with a 60s/300s backoff, and a
 * `failed()` that marks the deposit failed after both tries are exhausted.
 *
 * Deliberately does NOT hold the plaintext temp file as a constructor
 * property — see `PipelineWorkspace`'s docblock for why a shared `TempFile`
 * object can't survive `Bus::chain()`'s per-job serialization.
 */
abstract class PipelineJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $mediaFileUuid,
    ) {
        $this->onQueue('media');
    }

    public function uniqueId(): string
    {
        return $this->mediaFileUuid;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function failed(Throwable $exception): void
    {
        app(MarkMediaFileFailed::class)->handle($this->mediaFileUuid, static::class, $exception);
    }
}
