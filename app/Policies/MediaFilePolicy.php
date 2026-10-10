<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Deposit\OeuvreStatus;
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
     */
    public function delete(User $user, MediaFile $mediaFile): bool
    {
        return $user->id === $mediaFile->oeuvre->author_id
            && OeuvreStatus::isAuthorEditable($mediaFile->oeuvre->status);
    }
}
