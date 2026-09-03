<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Deposit\PipelineWorkspace;

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
    }
}
