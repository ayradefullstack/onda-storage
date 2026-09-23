<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\UploadSession;
use App\Models\User;

/**
 * An upload session is private scaffolding belonging to whoever started it —
 * every ability collapses to the same ownership check (CLAUDE.md's access
 * control layers: signed URL, then Policy, then session binding).
 */
final class UploadSessionPolicy
{
    public function view(User $user, UploadSession $session): bool
    {
        return $user->id === $session->user_id;
    }

    public function update(User $user, UploadSession $session): bool
    {
        return $user->id === $session->user_id;
    }

    public function delete(User $user, UploadSession $session): bool
    {
        return $user->id === $session->user_id;
    }
}
