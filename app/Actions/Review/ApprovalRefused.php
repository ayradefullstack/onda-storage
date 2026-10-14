<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Domain\Deposit\Value\SubmissionVerdict;
use RuntimeException;

/**
 * The deposit cannot be registered as it stands: a file has not finished
 * the pipeline (or failed it), or an unconditional required document is
 * missing. Carries the whole verdict so the officer is shown every problem
 * at once, by name — never a generic refusal.
 */
final class ApprovalRefused extends RuntimeException
{
    public function __construct(
        public readonly SubmissionVerdict $verdict,
    ) {
        parent::__construct('This deposit cannot be registered yet.');
    }
}
