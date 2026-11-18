<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\MediaFile;
use App\Models\User;

/**
 * Ownership traces through the owning `Oeuvre`, not `uploaded_by` — the
 * copyright holder (the work's author) controls the deposit even when a
 * future co-author or staff member is the one who performed the upload.
 */
final class MediaFilePolicy
{
    /**
     * An admin may view any file — the review console's whole purpose —
     * while an author remains scoped to their own deposits. The `role:admin`
     * route middleware already keeps non-admins out of admin-only routes;
     * this is the ownership check for the routes both roles share
     * (`media.stream`, `media.link`).
     */
    public function view(User $user, MediaFile $mediaFile): bool
    {
        return $user->id === $mediaFile->oeuvre->author_id || $user->hasRole('admin');
    }

    /**
     * The vault-scale ORIGINAL (`media.link` / `media.stream`). Owner only —
     * an admin reviews deposits through derivatives (see
     * OeuvreFileReviewController) and is never handed original bytes.
     * `view` stays admin-inclusive: it is metadata and derivative access.
     */
    public function streamOriginal(User $user, MediaFile $mediaFile): bool
    {
        return $user->id === $mediaFile->oeuvre->author_id;
    }

    /**
     * Deciding on one file of a deposit (accept / reject / reset). Admin
     * only; whether this admin holds the review and whether the file can
     * take the decision are DecideMediaFile's questions, asked under a lock
     * so a refusal can say why (409 / 422) instead of collapsing into a 403.
     */
    public function review(User $user, MediaFile $mediaFile): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, MediaFile $mediaFile): bool
    {
        return $user->id === $mediaFile->oeuvre->author_id;
    }

    /**
     * Removing a file from a deposit. Gated on the *oeuvre's* status, not
     * the file's: a file belonging to a submitted or registered deposit is
     * part of a record under review, whatever state the file itself is in.
     * An admin is deliberately not admitted — an officer reviews a deposit,
     * they do not edit it.
     *
     * Whether this particular file can be removed right now (its pipeline
     * may still be running over it) is RemovalGate's question, asked
     * separately so a refusal can name the file rather than collapse into
     * a 403.
     *
     * A file the ONDA ACCEPTED is never the author's to remove, whatever
     * the oeuvre's status: under review, registered, and also once the
     * whole deposit was rejected (returned to the author). The decision
     * stands across the resubmission, so the file stays.
     */
    public function delete(User $user, MediaFile $mediaFile): bool
    {
        return $user->id === $mediaFile->oeuvre->author_id
            && $mediaFile->review_status !== ReviewStatusEnum::ACCEPTED
            && ($mediaFile->oeuvre->status->isAuthorEditable() || self::isRemovableUnderReview($mediaFile));
    }

    /**
     * The narrow opening in the review freeze: while the oeuvre is
     * `under_review`, its author may remove
     *  - a file the officer REJECTED (then upload a replacement into that
     *    slot — InitUpload), or
     *  - their own REPLACEMENT while the officer has not decided it (still
     *    pending, whether ready, failed or quarantined), to fix a wrong
     *    upload or retry a failed one; the slot reopens for the same
     *    removed file.
     * An ACCEPTED file is never removable. Nothing else of an oeuvre under
     * review is the author's to change. RemoveMediaFile re-checks all of it
     * under the row locks.
     */
    public static function isRemovableUnderReview(MediaFile $mediaFile): bool
    {
        return $mediaFile->oeuvre->status === OeuvreStatus::UNDER_REVIEW
            && self::reviewAllowsRemoval($mediaFile);
    }

    /** The file-level half of isRemovableUnderReview. */
    public static function reviewAllowsRemoval(MediaFile $mediaFile): bool
    {
        return $mediaFile->review_status === ReviewStatusEnum::REJECTED
            || ($mediaFile->replaces_media_file_id !== null && $mediaFile->review_status === ReviewStatusEnum::PENDING);
    }
}
