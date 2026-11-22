<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Exceptions;

use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
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
        public readonly ?OeuvreStatus $currentStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function heldBy(User $holder): self
    {
        return new self(
            __('review.held_by', ['name' => $holder->name]),
            holder: $holder,
        );
    }

    public static function statusMoved(OeuvreStatus $currentStatus): self
    {
        return new self(
            __('review.status_moved', ['status' => $currentStatus->label()]),
            currentStatus: $currentStatus,
        );
    }
}
