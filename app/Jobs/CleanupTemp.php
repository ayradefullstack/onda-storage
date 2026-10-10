<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\PipelineWorkspace;
use App\Models\MediaFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Explicit, final unlink of the plaintext temp file — the last job in the
 * chain, not a destructor. The hourly schedule entry (routes/console.php)
 * is the backstop for anything that never reaches this point (a mid-chain
 * crash that exhausts retries before RecordDeposit, an orphaned quarantine
 * cleanup, etc).
 */
final class CleanupTemp extends PipelineJob
{
    public int $timeout = 300;

    public function handle(): void
    {
        PipelineWorkspace::delete($this->mediaFileUuid);

        $this->queuePreviews();
    }

    /**
     * The chain's last step: the deposit is recorded and `ready`, so the
     * (slow, optional) consultation derivatives can be queued on their own
     * queue. A preview must never be able to fail or delay a deposit, so a
     * dispatch problem is logged, not thrown.
     */
    private function queuePreviews(): void
    {
        try {
            $ready = MediaFile::where('uuid', $this->mediaFileUuid)
                ->where('status', MediaFileStatus::READY)
                ->exists();

            if ($ready) {
                GenerateConsultationDerivative::dispatch($this->mediaFileUuid);
            }
        } catch (Throwable $e) {
            Log::warning("CleanupTemp: could not queue previews for [{$this->mediaFileUuid}].", ['error' => $e->getMessage()]);
        }
    }
}
