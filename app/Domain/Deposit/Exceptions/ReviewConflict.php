<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Exceptions;

use App\Models\User;

/**
 * Another officer holds this deposit, or its status moved since the page
 * was loaded. Either way the caller must be told *what* happened and by
 * whom, not simply refused — see OeuvreStatusMachine's concurrency note.
 */
final class ReviewConflict extends DepositException
{
    private function __construct(
        string $message,
        public readonly ?User $holder = null,
        public readonly ?string $currentStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function heldBy(User $holder): self
    {
        return new self(
            "{$holder->name} is already reviewing this deposit. Take it over explicitly to decide on it.",
            holder: $holder,
        );
    }

    public static function statusMoved(string $currentStatus): self
    {
        return new self(
            "This deposit is now \"{$currentStatus}\". Reload before deciding on it.",
            currentStatus: $currentStatus,
        );
    }
}
