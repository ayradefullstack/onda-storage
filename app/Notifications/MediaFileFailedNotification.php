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
        $t = static fn (string $k, array $r = []): string => __('mail.file_failed.'.$k, $r, $locale);

        return (new MailMessage)
            ->subject($t('subject'))
            ->view('mail.corporate', [
                'subject' => $t('subject'),
                'locale' => $locale,
                'title' => $t('title'),
                'greeting' => $t('greeting'),
                'introLines' => [
                    $t('line_failed', ['file' => $this->mediaFile->original_name]),
                    $t('line_team'),
                    $t('line_contact'),
                ],
                'showWarning' => false,
            ]);
    }
}
