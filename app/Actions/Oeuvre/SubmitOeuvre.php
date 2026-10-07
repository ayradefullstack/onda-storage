<?php

declare(strict_types=1);

namespace App\Actions\Oeuvre;

use App\Domain\Deposit\OeuvreStatus;
use App\Domain\Deposit\OeuvreStatusMachine;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\User;
use App\Notifications\OeuvreSubmittedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * An author submits (or resubmits) a deposit for review.
 *
 * The transition itself — the gate, the guard, the history row — belongs
 * to OeuvreStatusMachine. This action is the side effect around it:
 * telling the officers. Per CLAUDE.md the post-transition pipeline is
 * explicit, not event-driven; events are reserved for things that may
 * legitimately be missed, and an officer never learning a deposit arrived
 * is not one of them.
 */
final class SubmitOeuvre
{
    public function __construct(
        private readonly OeuvreStatusMachine $machine,
    ) {}

    public function handle(Oeuvre $oeuvre, User $author): OeuvreReview
    {
        // Asked before the transition: afterwards the oeuvre carries the
        // rejection that is about to become "the previous round", and the
        // answer would always be true.
        $isResubmission = $oeuvre->isResubmission();

        $review = $this->machine->transition($oeuvre, OeuvreStatus::SUBMITTED, $author);

        $oeuvre->loadMissing(['author', 'registerTypeCollege']);

        // whereHas, not Spatie's `role()` scope: that scope throws
        // RoleDoesNotExist when the role row is missing, which would turn a
        // perfectly valid submission into a 500 on any environment where
        // RoleAndUserSeeder has not run. No officers to notify is a
        // deployment state, not an error in the author's submission.
        $admins = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'admin'))
            ->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new OeuvreSubmittedNotification($oeuvre, $isResubmission));
        }

        return $review;
    }
}
