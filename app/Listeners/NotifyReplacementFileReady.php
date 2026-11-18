<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Events\ReplacementFileReady;
use App\Models\MediaFile;
use App\Models\User;
use App\Notifications\ReplacementFileUploaded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the officer that a replacement is ready to review: the holder of
 * the oeuvre's review, or — with nobody holding it — every admin. Only
 * while the deposit is under review: outside it nobody is told.
 *
 * Once per replacement: a pipeline retry that reaches `ready` again finds
 * the marker and sends nothing.
 */
final class NotifyReplacementFileReady
{
    public function handle(ReplacementFileReady $event): void
    {
        $replacement = MediaFile::query()
            ->where('uuid', $event->mediaFileUuid)
            ->with(['oeuvre.author', 'oeuvre.registerTypeCollege', 'collegeOeuvreFile', 'replaces'])
            ->first();

        if ($replacement === null || $replacement->replaces === null) {
            return;
        }

        // Only while someone reviews the deposit. A replacement uploaded
        // into a deposit returned to its author (`rejected`) reaches nobody:
        // the resubmission's own notification covers it. Checked before the
        // marker, which is never set for it.
        if ($replacement->oeuvre->status !== OeuvreStatus::UNDER_REVIEW) {
            return;
        }

        if (! Cache::add('replacement-ready-notified:'.$replacement->uuid, true, now()->addDays(90))) {
            return;
        }

        $oeuvre = $replacement->oeuvre;
        $holder = $oeuvre->reviewed_by !== null
            ? User::query()->find($oeuvre->reviewed_by)
            : null;

        $recipients = $holder !== null ? collect([$holder]) : User::role('admin')->get();

        Notification::send($recipients, new ReplacementFileUploaded($oeuvre, $replacement, $replacement->replaces));
    }
}
