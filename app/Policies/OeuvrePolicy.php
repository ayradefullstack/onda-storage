<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Oeuvre;
use App\Models\User;

final class OeuvrePolicy
{
    public function view(User $user, Oeuvre $oeuvre): bool
    {
        return $user->id === $oeuvre->author_id;
    }

    /**
     * Governs both editing the work itself and uploading files to it —
     * a work is only ever mutable by the author who owns it.
     */
    public function update(User $user, Oeuvre $oeuvre): bool
    {
        return $user->id === $oeuvre->author_id;
    }
}
