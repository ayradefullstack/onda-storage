<?php

declare(strict_types=1);

namespace App\Actions\Review;

use App\Domain\Deposit\Exceptions\ReasonRequired;
use App\Domain\Deposit\Exceptions\ReviewConflict;
use App\Enums\FileAccessLog\ActionEnum;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\FileAccessLog;
use App\Models\MediaFile;
use App\Models\MediaFileReview;
use App\Models\Oeuvre;
use App\Models\User;
use App\Notifications\MediaFileRejected;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An officer's decision on ONE file of a deposit under review: accept it,
 * reject it with a reason, or put it back to pending. The only writer of
 * `media_files.review_status`.
 *
 * It never moves `oeuvres.status`: a rejected file leaves the deposit
 * under review. What it changes is whether ApproveOeuvre will register the
 * deposit (every file must be `accepted`).
 *
 * Locks the OEUVRE row first, then the file row — the same order
 * OeuvreStatusMachine uses (it locks only the oeuvre). A file decision and
 * an approve / reject of the same deposit therefore serialise on the
 * oeuvre row: an approve that commits first makes this a 409 "already
 * decided"; a file rejection that commits first makes the approve's guard
 * see it. DeduplicateFile locks media_files rows but never an oeuvre, so
 * there is no cycle with it either.
 *
 * Every rule is re-checked inside the lock; the `allowed` list the pages
 * show (self::allowed) is advisory.
 *
 * Review state is per row: two rows sharing a sha256 (dedup) are decided
 * independently, and nothing here reads or writes the other row.
 *
 * Neither the column nor `media_file_reviews` is part of a hash chain.
 */
final class DecideMediaFile
{
    public const ACCEPT = 'accept';

    public const REJECT = 'reject';

    public const RESET = 'reset';

    /** Why `allowed` is empty — machine keys, rendered by the page. */
    public const NOT_UNDER_REVIEW = 'not_under_review';

    public const NOT_HOLDER = 'not_holder';

    public const FILE_NOT_READY = 'file_not_ready';

    public const OEUVRE_DECIDED = 'oeuvre_decided';

    /** Pipeline states a file can be REJECTED in (accept needs `ready`). */
    private const REJECTABLE = [
        MediaFileStatus::READY,
        MediaFileStatus::FAILED,
        MediaFileStatus::QUARANTINED,
    ];

    /**
     * @return array{file: MediaFile, review: MediaFileReview|null, replayed: bool}
     *
     * @throws ReviewConflict another officer holds the deposit, or it is no longer under review
     * @throws FileDecisionRefused the file's pipeline state does not allow this decision
     * @throws ReasonRequired a rejection without a reason
     */
    public function handle(MediaFile $file, ReviewStatusEnum $to, User $reviewer, ?string $reason): array
    {
        $reason = $to === ReviewStatusEnum::REJECTED ? $this->normaliseReason($reason) : null;

        if ($to === ReviewStatusEnum::REJECTED && $reason === null) {
            throw new ReasonRequired;
        }

        $result = DB::transaction(function () use ($file, $to, $reviewer, $reason): array {
            // Oeuvre first, then file. See the class docblock.
            $oeuvre = Oeuvre::query()->whereKey($file->oeuvre_id)->lockForUpdate()->firstOrFail();
            // withTrashed: a file the author removed is refused by name, not 404.
            $locked = MediaFile::withTrashed()->whereKey($file->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->oeuvre_id !== $oeuvre->id) {
                throw FileDecisionRefused::foreignFile();
            }

            if ($locked->trashed()) {
                throw FileDecisionRefused::removed();
            }

            if ($oeuvre->status !== OeuvreStatus::UNDER_REVIEW) {
                throw ReviewConflict::statusMoved($oeuvre->status);
            }

            if ($oeuvre->reviewed_by !== $reviewer->id) {
                $holder = $oeuvre->reviewed_by === null ? null : User::query()->find($oeuvre->reviewed_by);

                throw $holder === null ? ReviewConflict::statusMoved($oeuvre->status) : ReviewConflict::heldBy($holder);
            }

            $this->assertFileAllows($locked, $to);

            $from = $locked->review_status;

            if ($from === $to && ($to !== ReviewStatusEnum::REJECTED || $locked->review_reason === $reason)) {
                return ['file' => $locked, 'review' => null, 'replayed' => true, 'oeuvre' => $oeuvre];
            }

            $now = Carbon::now();

            $locked->review_status = $to;
            $locked->reviewed_by = $reviewer->id;
            $locked->reviewed_at = $now;
            $locked->review_reason = $reason;
            $locked->save();

            $review = new MediaFileReview;
            $review->media_file_id = $locked->id;
            $review->oeuvre_id = $oeuvre->id;
            $review->reviewer_id = $reviewer->id;
            $review->from_status = $from;
            $review->to_status = $to;
            $review->reason = $reason;
            $review->was_consulted = $this->wasConsulted($locked, $reviewer);
            $review->decided_at = $now;
            $review->save();

            return ['file' => $locked, 'review' => $review, 'replayed' => false, 'oeuvre' => $oeuvre];
        });

        $file->setRawAttributes($result['file']->getAttributes(), sync: true);

        // Only a rejection tells the author anything; an acceptance waits
        // for the deposit's own outcome. Dispatched once the transaction
        // has committed (and queued after commit as well).
        if ($result['review'] !== null && $to === ReviewStatusEnum::REJECTED) {
            $oeuvre = $result['oeuvre'];
            $oeuvre->loadMissing(['author', 'registerTypeCollege']);
            $file->loadMissing('collegeOeuvreFile');
            $oeuvre->author?->notify((new MediaFileRejected($oeuvre, $file, (string) $reason, $result['review']->decided_at))->afterCommit());
        }

        return ['file' => $result['file'], 'review' => $result['review'], 'replayed' => $result['replayed']];
    }

    /**
     * What the officer may do to this file right now, and — when nothing —
     * why. Computed with the same rules handle() enforces under the lock;
     * advisory, like every read.
     *
     * @return array{actions: list<string>, reason: string|null}
     */
    public static function allowed(Oeuvre $oeuvre, MediaFile $file, User $officer): array
    {
        if ($oeuvre->status === OeuvreStatus::REGISTERED || $oeuvre->status === OeuvreStatus::REJECTED) {
            return ['actions' => [], 'reason' => self::OEUVRE_DECIDED];
        }

        if ($oeuvre->status !== OeuvreStatus::UNDER_REVIEW || ! $officer->hasRole('admin')) {
            return ['actions' => [], 'reason' => self::NOT_UNDER_REVIEW];
        }

        if ($oeuvre->reviewed_by !== $officer->id) {
            return ['actions' => [], 'reason' => self::NOT_HOLDER];
        }

        $actions = [];
        $current = $file->review_status;

        if ($file->status === MediaFileStatus::READY && $current !== ReviewStatusEnum::ACCEPTED) {
            $actions[] = self::ACCEPT;
        }

        // Rejecting an already-rejected file again is a new reason, so it
        // stays on offer (as "Modifier la décision").
        if (in_array($file->status, self::REJECTABLE, true)) {
            $actions[] = self::REJECT;
        }

        if ($current !== ReviewStatusEnum::PENDING && in_array($file->status, self::REJECTABLE, true)) {
            $actions[] = self::RESET;
        }

        return ['actions' => $actions, 'reason' => $actions === [] ? self::FILE_NOT_READY : null];
    }

    /**
     * @throws FileDecisionRefused
     */
    private function assertFileAllows(MediaFile $file, ReviewStatusEnum $to): void
    {
        $ok = match ($to) {
            ReviewStatusEnum::ACCEPTED => $file->status === MediaFileStatus::READY,
            ReviewStatusEnum::REJECTED, ReviewStatusEnum::PENDING => in_array($file->status, self::REJECTABLE, true),
        };

        if (! $ok) {
            throw FileDecisionRefused::fileStatus($file->status, $to);
        }
    }

    /**
     * Audit evidence only: had this officer opened this file through the
     * consultation routes before deciding? Never blocks anything.
     */
    private function wasConsulted(MediaFile $file, User $reviewer): bool
    {
        return FileAccessLog::query()
            ->where('media_file_id', $file->id)
            ->where('user_id', $reviewer->id)
            ->whereIn('action', [ActionEnum::ADMIN_CONSULT, ActionEnum::CONSULT_ORIGINAL])
            ->exists();
    }

    private function normaliseReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        return ($reason === null || $reason === '') ? null : $reason;
    }
}
