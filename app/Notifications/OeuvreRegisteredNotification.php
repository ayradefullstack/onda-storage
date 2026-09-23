<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Sent to the author when an officer registers their deposit.
 * See OeuvreNotification for the payload and channel rationale.
 */
final class OeuvreRegisteredNotification extends OeuvreNotification
{
    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'i18n_key' => 'notifications.oeuvre.registered',
            'params' => [
                // ISO 8601, not a formatted date: how a date reads is the
                // reader's locale's business, and that is not known here.
                'registered_at' => $this->oeuvre->registered_at?->toIso8601String(),
            ],
            'url' => route('oeuvres.show', $this->oeuvre->uuid),
        ];
    }
}
