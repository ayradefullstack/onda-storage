<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Domain\Deposit\OeuvreStatus;
use App\Domain\Deposit\OeuvreStatusMachine;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\User;
use App\Notifications\OeuvreRejectedNotification;

/**
 * An officer rejects a deposit, with a reason.
 *
 * The reason is mandatory — OeuvreStatusMachine refuses the transition
 * without one, including a whitespace-only one — and reaches the author
 * verbatim in the notification. It is the only thing that tells them what
 * to fix, and a rejected deposit reopens for exactly that purpose.
 *
 * `$review->reason` rather than the raw argument: the machine trims it,
 * and what the author is told must be what the history recorded.
 */
final class RejectOeuvre
{
    public function __construct(
        private readonly OeuvreStatusMachine $machine,
    ) {}

    public function handle(Oeuvre $oeuvre, User $officer, ?string $reason, bool $takeOver = false): OeuvreReview
    {
        $review = $this->machine->transition(
            $oeuvre,
            OeuvreStatus::REJECTED,
            $officer,
            reason: $reason,
            takeOver: $takeOver,
        );

        $oeuvre->loadMissing(['author', 'registerTypeCollege']);
        $oeuvre->author?->notify(new OeuvreRejectedNotification($oeuvre, (string) $review->reason));

        return $review;
    }
}
