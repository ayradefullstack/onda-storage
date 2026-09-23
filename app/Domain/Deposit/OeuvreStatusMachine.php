<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Deposit\Exceptions\IllegalTransition;
use App\Domain\Deposit\Exceptions\ReasonRequired;
use App\Domain\Deposit\Exceptions\ReviewConflict;
use App\Domain\Deposit\Exceptions\SubmissionRefused;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place `oeuvres.status` is ever written.
 *
 * Nothing else in the application may call `$oeuvre->update(['status' =>
 * ...])`: a status change that does not pass through here leaves no
 * `oeuvre_reviews` row, so the deposit's history silently loses a step —
 * and the history is the artifact that makes the deposit defensible.
 *
 * -------------------------------------------------------------------
 * The transition table, as implemented.
 * -------------------------------------------------------------------
 *   draft         -> submitted      owning author, submission gate passes
 *   rejected      -> submitted      owning author, submission gate passes
 *   submitted     -> under_review   admin
 *   under_review  -> registered     admin holding it
 *   under_review  -> rejected       admin holding it, non-empty reason
 *   registered    -> (nothing)      TERMINAL
 *
 * `registered` has no entry in self::TRANSITIONS at all. That is the
 * enforcement, not a special case layered on top of one: there is no code
 * path that could move a registered deposit, for any actor, with any flag.
 *
 * -------------------------------------------------------------------
 * Concurrency — the holder model, and why it was chosen.
 * -------------------------------------------------------------------
 * Two officers opening the same deposit is normal in a small office. The
 * failure to prevent is two opposite decisions a minute apart, the second
 * silently overwriting the first.
 *
 * Moving a deposit to `under_review` records the officer in
 * `oeuvres.reviewed_by` — they hold it. A decision (`registered` /
 * `rejected`) from anyone else is refused with ReviewConflict naming the
 * holder; the second officer may take it over explicitly, which reassigns
 * the holder and is visible in the UI before they decide.
 *
 * This was chosen over optimistic locking (refuse if the status changed
 * since page load) because optimistic locking only detects the collision
 * after the second officer has already read every file and written a
 * reasoned rejection, and it can say only "this changed, reload" — it
 * cannot say who holds it or offer to take it. The holder model surfaces
 * the conflict when the second officer opens the deposit, which is the
 * moment the duplicated work can still be avoided.
 *
 * The holder check is a policy over humans, not a race guard, so it is
 * backed by one: every transition runs inside a transaction that re-reads
 * the row with `lockForUpdate()` and re-checks the from-status against the
 * locked value. Two simultaneous approve/reject requests serialise, and
 * the loser gets ReviewConflict::statusMoved() rather than overwriting.
 */
final class OeuvreStatusMachine
{
    private const ACTOR_AUTHOR = 'author';

    private const ACTOR_ADMIN = 'admin';

    /**
     * from => to => ['actor' => author|admin, 'reason' => required?]
     *
     * @var array<string, array<string, array{actor: string, reason: bool}>>
     */
    private const TRANSITIONS = [
        OeuvreStatus::DRAFT => [
            OeuvreStatus::SUBMITTED => ['actor' => self::ACTOR_AUTHOR, 'reason' => false],
        ],
        OeuvreStatus::REJECTED => [
            OeuvreStatus::SUBMITTED => ['actor' => self::ACTOR_AUTHOR, 'reason' => false],
        ],
        OeuvreStatus::SUBMITTED => [
            OeuvreStatus::UNDER_REVIEW => ['actor' => self::ACTOR_ADMIN, 'reason' => false],
        ],
        OeuvreStatus::UNDER_REVIEW => [
            OeuvreStatus::REGISTERED => ['actor' => self::ACTOR_ADMIN, 'reason' => false],
            OeuvreStatus::REJECTED => ['actor' => self::ACTOR_ADMIN, 'reason' => true],
        ],
        // OeuvreStatus::REGISTERED is absent on purpose. Do not add it.
    ];

    public function __construct(
        private readonly SubmissionGate $gate,
    ) {}

    /**
     * Perform a transition, recording it. Returns the `oeuvre_reviews` row
     * that is now the deposit's newest history entry.
     *
     * @param  bool  $takeOver  the deciding officer is knowingly taking the
     *                          deposit off the officer currently holding it
     *
     * @throws IllegalTransition
     * @throws ReasonRequired
     * @throws ReviewConflict
     * @throws SubmissionRefused
     */
    public function transition(Oeuvre $oeuvre, string $to, User $actor, ?string $reason = null, bool $takeOver = false): OeuvreReview
    {
        $reason = $this->normaliseReason($reason);

        return DB::transaction(function () use ($oeuvre, $to, $actor, $reason, $takeOver): OeuvreReview {
            // Re-read under a row lock: the status this method was called
            // about may already be stale, and every check below must run
            // against the value no other request can change until commit.
            $locked = Oeuvre::query()->whereKey($oeuvre->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            $rule = $this->ruleFor($from, $to);

            $this->assertActorMay($rule['actor'], $from, $to, $actor, $locked);

            if ($rule['reason'] && $reason === null) {
                throw new ReasonRequired;
            }

            if ($to === OeuvreStatus::SUBMITTED) {
                $this->assertSubmittable($locked);
            }

            if ($from === OeuvreStatus::UNDER_REVIEW) {
                $this->assertHolder($locked, $actor, $takeOver);
            }

            $this->apply($locked, $to, $actor);

            $review = new OeuvreReview;
            $review->oeuvre_id = $locked->id;
            $review->actor_id = $actor->id;
            $review->from_status = $from;
            $review->to_status = $to;
            $review->reason = $reason;
            $review->save();

            // The caller still holds the instance it passed in; keep it in
            // step with what was just committed rather than making every
            // call site remember to refresh.
            $oeuvre->setRawAttributes($locked->getAttributes(), sync: true);

            return $review;
        });
    }

    /**
     * Reassign the officer holding an `under_review` deposit. Not a status
     * transition — the status does not move — so it writes no
     * `oeuvre_reviews` row; the take-over becomes part of the history
     * through the `actor_id` of whatever decision follows it.
     *
     * @throws IllegalTransition
     * @throws ReviewConflict
     */
    public function takeOver(Oeuvre $oeuvre, User $actor): void
    {
        if (! $actor->hasRole(self::ACTOR_ADMIN)) {
            throw IllegalTransition::actor($oeuvre->status, OeuvreStatus::UNDER_REVIEW);
        }

        DB::transaction(function () use ($oeuvre, $actor): void {
            $locked = Oeuvre::query()->whereKey($oeuvre->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== OeuvreStatus::UNDER_REVIEW) {
                throw ReviewConflict::statusMoved($locked->status);
            }

            $locked->reviewed_by = $actor->id;
            // Carbon::now(), not the now() helper: AppServiceProvider swaps
            // the app-wide default to CarbonImmutable while these columns
            // cast to the mutable Illuminate\Support\Carbon (the same note
            // EncryptedLocalVault carries).
            $locked->reviewed_at = Carbon::now();
            $locked->save();

            $oeuvre->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /**
     * Whether `$actor` could perform `$to` on this oeuvre right now — gate
     * and holder included. Used by the UI to decide what to offer; the
     * answer is advisory, since `transition()` re-checks under a lock.
     */
    public function can(Oeuvre $oeuvre, string $to, User $actor): bool
    {
        try {
            $rule = $this->ruleFor($oeuvre->status, $to);
            $this->assertActorMay($rule['actor'], $oeuvre->status, $to, $actor, $oeuvre);

            if ($to === OeuvreStatus::SUBMITTED) {
                $this->assertSubmittable($oeuvre);
            }
        } catch (IllegalTransition|SubmissionRefused) {
            return false;
        }

        return true;
    }

    /**
     * @return array{actor: string, reason: bool}
     *
     * @throws IllegalTransition
     */
    private function ruleFor(string $from, string $to): array
    {
        if (OeuvreStatus::isTerminal($from)) {
            throw IllegalTransition::terminal($from, $to);
        }

        return self::TRANSITIONS[$from][$to] ?? throw IllegalTransition::between($from, $to);
    }

    /**
     * @throws IllegalTransition
     */
    private function assertActorMay(string $requiredActor, string $from, string $to, User $actor, Oeuvre $oeuvre): void
    {
        $allowed = $requiredActor === self::ACTOR_AUTHOR
            ? $actor->id === $oeuvre->author_id
            : $actor->hasRole(self::ACTOR_ADMIN);

        if (! $allowed) {
            throw IllegalTransition::actor($from, $to);
        }
    }

    /**
     * @throws SubmissionRefused
     */
    private function assertSubmittable(Oeuvre $oeuvre): void
    {
        $verdict = $this->gate->evaluate($oeuvre);

        if (! $verdict->passes()) {
            throw new SubmissionRefused($verdict);
        }
    }

    /**
     * @throws ReviewConflict
     */
    private function assertHolder(Oeuvre $oeuvre, User $actor, bool $takeOver): void
    {
        $holderId = $oeuvre->reviewed_by;

        if ($holderId === null || $holderId === $actor->id || $takeOver) {
            return;
        }

        $holder = User::query()->find($holderId);

        throw $holder === null
            ? ReviewConflict::statusMoved($oeuvre->status)
            : ReviewConflict::heldBy($holder);
    }

    /**
     * Timestamps are set here rather than by the caller, so a status and
     * the stamp that explains it can never disagree.
     */
    private function apply(Oeuvre $oeuvre, string $to, User $actor): void
    {
        $oeuvre->status = $to;

        match ($to) {
            OeuvreStatus::SUBMITTED => $this->applySubmitted($oeuvre),
            OeuvreStatus::UNDER_REVIEW => $this->applyUnderReview($oeuvre, $actor),
            OeuvreStatus::REGISTERED => $this->applyDecision($oeuvre, $actor, registered: true),
            OeuvreStatus::REJECTED => $this->applyDecision($oeuvre, $actor, registered: false),
            default => null,
        };

        $oeuvre->save();
    }

    private function applySubmitted(Oeuvre $oeuvre): void
    {
        // Overwritten on a resubmission: `submitted_at` is when the deposit
        // last entered the officers' queue, which is what that queue orders
        // by. The first submission's date is not lost — it is the
        // `created_at` of the first `oeuvre_reviews` row.
        $oeuvre->submitted_at = Carbon::now();

        // The previous round's officer no longer holds it.
        $oeuvre->reviewed_by = null;
        $oeuvre->reviewed_at = null;
    }

    private function applyUnderReview(Oeuvre $oeuvre, User $actor): void
    {
        $oeuvre->reviewed_by = $actor->id;
        $oeuvre->reviewed_at = Carbon::now();
    }

    private function applyDecision(Oeuvre $oeuvre, User $actor, bool $registered): void
    {
        $oeuvre->reviewed_by = $actor->id;
        $oeuvre->reviewed_at = Carbon::now();

        if ($registered) {
            $oeuvre->registered_at = Carbon::now();
        }
    }

    /**
     * A reason of "" or "   " is no reason. Collapsing it to null here
     * means ReasonRequired fires on whitespace too, rather than a blank
     * line reaching the author as an explanation.
     */
    private function normaliseReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        return ($reason === null || $reason === '') ? null : $reason;
    }
}
