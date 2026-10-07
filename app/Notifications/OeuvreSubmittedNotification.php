<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Oeuvre;

/**
 * Sent to every admin when an author submits — or resubmits — a deposit.
 * See OeuvreNotification for the payload and channel rationale.
 */
final class OeuvreSubmittedNotification extends OeuvreNotification
{
    public function __construct(
        Oeuvre $oeuvre,
        private readonly bool $isResubmission,
    ) {
        parent::__construct($oeuvre);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            // A resubmission gets its own key rather than a flag on the
            // same sentence: an officer opening one needs to know it was
            // rejected before, at a glance, not after noticing a badge.
            'i18n_key' => $this->isResubmission
                ? 'notifications.oeuvre.resubmitted'
                : 'notifications.oeuvre.submitted',
            'is_resubmission' => $this->isResubmission,
            'params' => [
                'author' => (string) $this->oeuvre->author?->name,
                'college' => $this->oeuvre->registerTypeCollege === null
                    ? ''
                    : trim($this->oeuvre->registerTypeCollege->name_global),
                'files' => $this->oeuvre->mediaFiles()->count(),
            ],
            // An admin's notification points at the admin console, never
            // at the author's own page for the same deposit.
            'url' => route('admin.oeuvres.show', $this->oeuvre->uuid),
        ];
    }
}
