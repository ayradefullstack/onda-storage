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
        ?string $locale = null,
    ) {
        // Captured at dispatch: the mail renders in this locale, whatever
        // the worker's own locale is when it runs.
        $this->locale = $locale ?? app()->getLocale();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = (string) $this->locale;
        $t = static fn (string $k, array $r = []): string => __('mail.file_quarantined.'.$k, $r, $locale);

        return (new MailMessage)
            ->subject($t('subject'))
            ->view('mail.corporate', [
                'subject' => $t('subject'),
                'locale' => $locale,
                'title' => $t('title'),
                'greeting' => $t('greeting'),
                'introLines' => [
                    $t('line_quarantined', ['file' => $this->mediaFile->original_name, 'uuid' => $this->mediaFile->uuid]),
                    $t('line_review'),
                ],
                'showWarning' => false,
            ]);
    }
}
