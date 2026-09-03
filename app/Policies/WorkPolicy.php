<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Work;

final class WorkPolicy
{
    public function view(User $user, Work $work): bool
    {
        return $user->id === $work->author_id;
    }

    /**
     * Governs both editing the work itself and uploading files to it —
     * a work is only ever mutable by the author who owns it.
     */
    public function update(User $user, Work $work): bool
    {
        return $user->id === $work->author_id;
    }
}
