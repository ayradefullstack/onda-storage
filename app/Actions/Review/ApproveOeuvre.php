<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Domain\Deposit\OeuvreStatusMachine;
use App\Domain\Deposit\SubmissionGate;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\User;
use App\Notifications\OeuvreRegisteredNotification;
use Illuminate\Support\Facades\DB;

/**
 * An officer registers a deposit. This is the last thing that ever
 * happens to it: `registered` is terminal, and OeuvreStatusMachine has no
 * outbound transition from it.
 *
 * Registration is refused unless the deposit is complete, and that is
 * decided here, on the server, never by the page that greyed a button:
 * every file must be `ready` (none uploading, scanning, processing, failed
 * or quarantined) and every *unconditional* required document must hold a
 * ready file. Those are exactly SubmissionGate's blockers, so the same gate
 * answers — an author could not have submitted in this state, but a file
 * can still fail or be quarantined after submission. Empty conditional
 * documents stay advisory: the officer judges whether they applied.
 *
 * The gate is read inside the transaction that holds the machine's row
 * lock, after the transition's own checks, so a conflict (someone else
 * holds or already decided the deposit) is reported before a completeness
 * problem. A refusal rolls the transition back.
 *
 * Only the author is notified — the other officers do not need a bell for
 * a decision they did not make.
 */
final class ApproveOeuvre
{
    public function __construct(
        private readonly OeuvreStatusMachine $machine,
        private readonly SubmissionGate $gate,
    ) {}

    /**
     * @throws ApprovalRefused
     */
    public function handle(Oeuvre $oeuvre, User $officer, bool $takeOver = false): OeuvreReview
    {
        try {
            $review = DB::transaction(function () use ($oeuvre, $officer, $takeOver): OeuvreReview {
                $review = $this->machine->transition(
                    $oeuvre,
                    OeuvreStatus::REGISTERED,
                    $officer,
                    takeOver: $takeOver,
                );

                $verdict = $this->gate->evaluate($oeuvre);

                if (! $verdict->passes()) {
                    throw new ApprovalRefused($verdict);
                }

                return $review;
            });
        } catch (ApprovalRefused $e) {
            // The machine synced the caller's instance to the registered
            // row it has just rolled back.
            $oeuvre->refresh();

            throw $e;
        }

        $oeuvre->loadMissing(['author', 'registerTypeCollege']);
        $oeuvre->author?->notify(new OeuvreRegisteredNotification($oeuvre));

        return $review;
    }
}
