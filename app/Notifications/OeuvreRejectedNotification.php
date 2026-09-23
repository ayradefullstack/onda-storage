<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Oeuvre;

/**
 * Sent to the author when an officer rejects their deposit.
 *
 * The officer's reason travels in the payload **verbatim**. It is the one
 * part of this notification that is not translated and must not be: it is
 * a sentence a human wrote about this specific deposit, and it is the only
 * thing that tells the author what to fix. A rejection whose reason the
 * author has to go and hunt for is a support call.
 *
 * See OeuvreNotification for the rest of the payload and channel rationale.
 */
final class OeuvreRejectedNotification extends OeuvreNotification
{
    public function __construct(
        Oeuvre $oeuvre,
        private readonly string $reason,
    ) {
        parent::__construct($oeuvre);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'i18n_key' => 'notifications.oeuvre.rejected',
            'params' => [],
            // Deliberately outside `params`: it is not a translation
            // placeholder, it is the payload. The bell renders it as its
            // own quoted block, so it survives the row being truncated.
            'reason' => $this->reason,
            'url' => route('oeuvres.show', $this->oeuvre->uuid),
        ];
    }
}
