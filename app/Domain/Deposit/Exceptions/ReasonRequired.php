<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Exceptions;

/**
 * A rejection without a reason. The reason reaches the author verbatim in
 * the rejection notification — a rejection they have to go and hunt for is
 * a support call.
 */
final class ReasonRequired extends DepositException
{
    public function __construct()
    {
        parent::__construct('A rejection must carry a reason for the author.');
    }
}
