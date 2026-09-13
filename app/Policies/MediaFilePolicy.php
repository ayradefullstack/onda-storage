<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MediaFile;
use App\Models\User;

/**
 * Ownership traces through the owning `Work`, not `uploaded_by` — the
 * copyright holder (the work's author) controls the deposit even when a
 * future co-author or staff member is the one who performed the upload.
 * Not yet wired to a route in P3 (read paths land in P4); registered now so
 * `Gate::policy()` resolution is in place ahead of that phase.
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
        return $user->id === $mediaFile->work->author_id || $user->hasRole('admin');
    }

    public function update(User $user, MediaFile $mediaFile): bool
    {
        return $user->id === $mediaFile->work->author_id;
    }

    public function delete(User $user, MediaFile $mediaFile): bool
    {
        return $user->id === $mediaFile->work->author_id;
    }
}
