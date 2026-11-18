<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * To the officer holding the review (or, with no active holder, every
 * admin): the author's replacement for a rejected file is ready to be
 * decided. Sent once per replacement, when it reaches `ready` — never when
 * the upload starts, since nothing can be decided before that.
 *
 * Admin-side, so it may name the author. Same payload conventions as the
 * author notifications (an i18n key rendered in the reader's locale, a
 * label source). Database only, queued on `default`.
 */
final class ReplacementFileUploaded extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'replacement_uploaded';

    /** Long enough to say what was wrong, short enough for a bell row. */
    private const REASON_EXCERPT = 160;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly Oeuvre $oeuvre,
        private readonly MediaFile $replacement,
        private readonly MediaFile $replaced,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => 'default'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $college = $this->oeuvre->registerTypeCollege;
        $slot = $this->replacement->collegeOeuvreFile;

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
            'author' => $this->oeuvre->author?->name,
            'slot' => $slot === null ? null : [
                'fr' => $slot->title,
                'ar' => $slot->title_ar ?: $slot->title,
                'en' => $slot->title_en ?: $slot->title,
            ],
            'file_uuid' => $this->replacement->uuid,
            'file_name' => $this->replacement->original_name,
            'replaced_file_name' => $this->replaced->original_name,
            'i18n_key' => 'notifications.file.replacement',
            'params' => [
                'file' => $this->replacement->original_name,
                'old' => $this->replaced->original_name,
                'author' => (string) $this->oeuvre->author?->name,
            ],
            // Under `reason`, like the rejections: the bell renders it as
            // its own quoted block. Here it is the PREVIOUS rejection's reason.
            'reason' => Str::limit((string) $this->replaced->review_reason, self::REASON_EXCERPT),
            'url' => route('admin.oeuvres.files.review', ['oeuvre' => $this->oeuvre->uuid, 'mediaFile' => $this->replacement->uuid]),
        ];
    }
}
