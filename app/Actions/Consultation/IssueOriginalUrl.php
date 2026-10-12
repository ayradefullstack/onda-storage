<?php

declare(strict_types=1);

namespace App\Actions\Consultation;

use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * A short-lived signed URL to the consult-original endpoint, bound to the
 * issuing admin exactly like `IssueAssetUrl` (`admin` is inside the
 * signature and must equal the authenticated user). The same URL serves every
 * Range request of one playback or one PDF session.
 */
final class IssueOriginalUrl
{
    public function handle(Oeuvre $oeuvre, MediaFile $mediaFile, User $user): string
    {
        return URL::temporarySignedRoute(
            'admin.oeuvres.files.consult.original',
            now()->addMinutes((int) config('vault.consult.url_ttl_minutes')),
            [
                'oeuvre' => $oeuvre->uuid,
                'mediaFile' => $mediaFile->uuid,
                'admin' => $user->id,
            ],
        );
    }
}
