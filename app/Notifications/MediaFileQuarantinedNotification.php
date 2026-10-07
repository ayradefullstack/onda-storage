<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\MediaFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to admins (never auto-deleted, never the uploader — a quarantine
 * verdict needs human review before anyone is told anything, and a false
 * positive on a legitimate deposit must stay recoverable).
 */
final class MediaFileQuarantinedNotification extends Notification
{
    public function __construct(
        private readonly MediaFile $mediaFile,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Fichier mis en quarantaine — ONDA')
            ->greeting('Alerte administrateur')
            ->line("Le fichier \"{$this->mediaFile->original_name}\" (uuid {$this->mediaFile->uuid}) a été mis en quarantaine par l'analyse antivirus.")
            ->line('Les octets ont été déplacés, jamais supprimés — une revue manuelle est nécessaire avant toute décision.');
    }
}
