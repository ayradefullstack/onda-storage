<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\MediaFile;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the uploader when the P5 pipeline permanently fails a deposit
 * (2 attempts exhausted). Not queued — this is already running inside a
 * queued job's `failed()` handler, so one more mail send doesn't need its
 * own queue hop.
 */
final class MediaFileFailedNotification extends Notification
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
            ->subject('Échec du traitement de votre dépôt — ONDA')
            ->greeting('Bonjour,')
            ->line("Le traitement de votre fichier \"{$this->mediaFile->original_name}\" a échoué.")
            ->line('Notre équipe technique a été notifiée. Vous n\'avez pas besoin de redéposer ce fichier pour le moment.')
            ->line('Nous vous contacterons si une action de votre part est nécessaire.');
    }
}
