<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\MediaFile;
use App\Models\Oeuvre;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to the author when an officer rejects ONE file of their deposit.
 * One per rejection event: a repeat with the same reason writes nothing
 * and sends nothing; a new reason is a new decision and notifies again.
 *
 * Same payload conventions as OeuvreNotification (an i18n key rendered in
 * the reader's locale, a label source, the reason verbatim), with the
 * file's identity added. It never names the officer: the author hears
 * from "ONDA", not from a person.
 *
 * Database only, queued on `default` after the decision commits
 * (QueuedForAuthor; DecideMediaFile calls afterCommit()).
 */
final class MediaFileRejected extends Notification implements ShouldQueue
{
    use QueuedForAuthor;

    public const TYPE = 'file_rejected';

    public function __construct(
        private readonly Oeuvre $oeuvre,
        private readonly MediaFile $mediaFile,
        private readonly string $reason,
        private readonly DateTimeInterface $decidedAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $college = $this->oeuvre->registerTypeCollege;
        $slot = $this->mediaFile->collegeOeuvreFile;

        return [
            'type' => self::TYPE,
            'oeuvre_uuid' => $this->oeuvre->uuid,
            'reference' => $this->oeuvre->uuid,
            'title' => $this->oeuvre->title,
            'label_source' => [
                'uuid' => $this->oeuvre->uuid,
                'title' => $this->oeuvre->title,
                'college_name' => $college === null ? null : trim($college->name_global),
                'created_at' => $this->oeuvre->created_at?->toIso8601String(),
            ],
            'file_uuid' => $this->mediaFile->uuid,
            'file_name' => $this->mediaFile->original_name,
            // Captured as of the decision, in each language the slot has.
            'slot' => $slot === null ? null : [
                'fr' => $slot->title,
                'ar' => $slot->title_ar ?: $slot->title,
                'en' => $slot->title_en ?: $slot->title,
            ],
            'i18n_key' => 'notifications.file.rejected',
            'params' => ['file' => $this->mediaFile->original_name],
            'reason' => $this->reason,
            'decided_at' => $this->decidedAt->format(DATE_ATOM),
            'url' => route('oeuvres.show', $this->oeuvre->uuid),
        ];
    }
}
