<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Sent to the author when an officer opens their deposit for review.
 * Names no officer: the author is told that ONDA is examining the deposit,
 * not who. See OeuvreNotification for the payload and channel rationale.
 */
final class OeuvreUnderReviewNotification extends OeuvreNotification implements ShouldQueue
{
    use QueuedForAuthor;

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'i18n_key' => 'notifications.oeuvre.under_review',
            'params' => [],
            'url' => route('oeuvres.show', $this->oeuvre->uuid),
        ];
    }
}
