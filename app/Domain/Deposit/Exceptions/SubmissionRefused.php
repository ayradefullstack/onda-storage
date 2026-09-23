<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Exceptions;

use App\Domain\Deposit\Value\SubmissionVerdict;

/**
 * The submission gate refused. Carries the whole verdict, not a single
 * message, so the caller can show the author every blocker at once.
 */
final class SubmissionRefused extends DepositException
{
    public function __construct(
        public readonly SubmissionVerdict $verdict,
    ) {
        parent::__construct('This work is not ready to be submitted.');
    }
}
