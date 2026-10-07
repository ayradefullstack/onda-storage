<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Domain\Deposit\OeuvreStatus;
use App\Domain\Deposit\OeuvreStatusMachine;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\User;
use App\Notifications\OeuvreRegisteredNotification;

/**
 * An officer registers a deposit. This is the last thing that ever
 * happens to it: `registered` is terminal, and OeuvreStatusMachine has no
 * outbound transition from it.
 *
 * Only the author is notified — the other officers do not need a bell for
 * a decision they did not make.
 */
final class ApproveOeuvre
{
    public function __construct(
        private readonly OeuvreStatusMachine $machine,
    ) {}

    public function handle(Oeuvre $oeuvre, User $officer, bool $takeOver = false): OeuvreReview
    {
        $review = $this->machine->transition(
            $oeuvre,
            OeuvreStatus::REGISTERED,
            $officer,
            takeOver: $takeOver,
        );

        $oeuvre->loadMissing(['author', 'registerTypeCollege']);
        $oeuvre->author?->notify(new OeuvreRegisteredNotification($oeuvre));

        return $review;
    }
}
