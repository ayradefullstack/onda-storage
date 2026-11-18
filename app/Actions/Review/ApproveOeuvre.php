<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Domain\Deposit\OeuvreStatusMachine;
use App\Domain\Deposit\SubmissionGate;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\MediaFile;
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
 * On top of the gate, every file must have been ACCEPTED by an officer
 * (`media_files.review_status`, DecideMediaFile). Same lock: DecideMediaFile
 * locks this oeuvre row first, so a file rejection cannot slip in between
 * this check and the commit.
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
     * @throws FilesNotAccepted
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

                $notAccepted = self::filesNotAccepted($oeuvre);

                if ($notAccepted !== []) {
                    throw new FilesNotAccepted($notAccepted);
                }

                return $review;
            });
        } catch (ApprovalRefused|FilesNotAccepted $e) {
            // The machine synced the caller's instance to the registered
            // row it has just rolled back.
            $oeuvre->refresh();

            throw $e;
        }

        $oeuvre->loadMissing(['author', 'registerTypeCollege']);
        $oeuvre->author?->notify(new OeuvreRegisteredNotification($oeuvre));

        return $review;
    }

    /**
     * Every file of the deposit not (yet) accepted by an officer, in the
     * order the pages list files. Also the decision summary's checklist.
     *
     * @return list<array{name: string, reviewStatus: string}>
     */
    public static function filesNotAccepted(Oeuvre $oeuvre): array
    {
        return array_values($oeuvre->mediaFiles()
            ->where('review_status', '!=', ReviewStatusEnum::ACCEPTED)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'original_name', 'review_status'])
            ->map(fn (MediaFile $file): array => ['name' => $file->original_name, 'reviewStatus' => $file->review_status->key()])
            ->all());
    }
}
