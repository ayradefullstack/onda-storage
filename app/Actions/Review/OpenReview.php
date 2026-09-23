<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Domain\Deposit\Exceptions\ReviewConflict;
use App\Domain\Deposit\OeuvreStatus;
use App\Domain\Deposit\OeuvreStatusMachine;
use App\Models\Oeuvre;
use App\Models\User;

/**
 * An officer opens a submitted deposit for review, taking hold of it.
 *
 * Two distinct cases behind one button:
 *  - `submitted`    -> a real transition to `under_review`, recorded.
 *  - `under_review` -> the deposit is already open. If this officer holds
 *    it, nothing happens. If someone else does, they must take it over
 *    explicitly; without `$takeOver` this raises ReviewConflict naming the
 *    holder, which is what the UI turns into "Amina is reviewing this —
 *    take over?".
 *
 * No notification: the author does not need to know an officer opened
 * their file, only what was decided.
 *
 * @throws ReviewConflict
 */
final class OpenReview
{
    public function __construct(
        private readonly OeuvreStatusMachine $machine,
    ) {}

    public function handle(Oeuvre $oeuvre, User $officer, bool $takeOver = false): void
    {
        if ($oeuvre->status !== OeuvreStatus::UNDER_REVIEW) {
            $this->machine->transition($oeuvre, OeuvreStatus::UNDER_REVIEW, $officer);

            return;
        }

        if ($oeuvre->reviewed_by === $officer->id) {
            return;
        }

        if (! $takeOver) {
            $holder = User::query()->find($oeuvre->reviewed_by);

            throw $holder === null
                ? ReviewConflict::statusMoved($oeuvre->status)
                : ReviewConflict::heldBy($holder);
        }

        $this->machine->takeOver($oeuvre, $officer);
    }
}
