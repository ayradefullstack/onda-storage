<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Deposit\OeuvreStatus;
use App\Models\Oeuvre;
use App\Models\User;

final class OeuvrePolicy
{
    /**
     * Read-only access, in every status. An author can always open their
     * own deposit — a frozen one simply shows no controls.
     */
    public function view(User $user, Oeuvre $oeuvre): bool
    {
        return $user->id === $oeuvre->author_id;
    }

    /**
     * Governs editing the deposit: adding files, and removing them.
     *
     * Two conditions, not one: the author must own it, AND the deposit
     * must still be theirs to change. Once it leaves `draft` it belongs to
     * the review queue — a submitted, under-review or registered oeuvre
     * takes no new files and no removals, and that is enforced here rather
     * than by the page hiding its upload area. A `rejected` oeuvre
     * reopens: the whole point of a rejection is that the author can fix
     * it and resubmit.
     *
     * "Edit" never means the classification. Type, gestion, collège and
     * qualité are fixed at creation and there is deliberately no route,
     * request or policy method that changes them — every uploaded file
     * carries a `college_oeuvre_file_id` bound to the current collège, so
     * a fixed classification is what keeps that link from drifting.
     *
     * InitUpload carries its own status guard ahead of this check, purely
     * so an author uploading into a frozen work is told it is frozen
     * rather than told it is not theirs.
     */
    public function update(User $user, Oeuvre $oeuvre): bool
    {
        return $user->id === $oeuvre->author_id
            && OeuvreStatus::isAuthorEditable($oeuvre->status);
    }

    /**
     * Whether this user may *attempt* a submission — ownership and status
     * only. Whether the deposit is actually ready is SubmissionGate's
     * question, and it is asked separately so a refusal can explain
     * itself instead of collapsing into a 403.
     */
    public function submit(User $user, Oeuvre $oeuvre): bool
    {
        return $user->id === $oeuvre->author_id
            && OeuvreStatus::isAuthorEditable($oeuvre->status);
    }

    /**
     * Deleting the whole deposit. Same window as editing, and for the
     * stronger version of the same reason: a `submitted` deposit is in
     * front of an officer, and a `registered` one is a legal record —
     * neither is its author's to withdraw. `under_review` likewise.
     *
     * Whether it can be deleted *right now* also depends on no file being
     * mid-pipeline; that is RemovalGate's question, kept out of here so a
     * refusal can name the file instead of collapsing into a 403.
     */
    public function delete(User $user, Oeuvre $oeuvre): bool
    {
        return $user->id === $oeuvre->author_id
            && OeuvreStatus::isAuthorEditable($oeuvre->status);
    }

    /**
     * An officer opening a deposit in the admin console. Drafts are
     * readable (read-only, logged) — see `decide()` for why they are not
     * decidable.
     */
    public function inspect(User $user, Oeuvre $oeuvre): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * Open a review, approve or reject. Refused for a draft: it is still the
     * author's to change or delete at any moment, so a decision would be
     * made on content that can move underneath it. The status machine has no
     * draft -> reviewed edge either; this refuses earlier, as a 403, so the
     * direct request never reaches it.
     */
    public function decide(User $user, Oeuvre $oeuvre): bool
    {
        return $user->hasRole('admin') && $oeuvre->status !== OeuvreStatus::DRAFT;
    }
}
