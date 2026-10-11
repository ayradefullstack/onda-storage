<?php

declare(strict_types=1);

namespace App\Actions\Consultation;

use App\Models\ConsultationAsset;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * A short-lived signed URL for ONE derivative asset, bound to the issuing
 * user: `admin` (their id) is inside the signature, and the middleware
 * rejects the request unless it equals the authenticated user. A copied URL
 * is therefore useless in another session, and a signature alone — a bearer
 * token — is never enough.
 */
final class IssueAssetUrl
{
    public function handle(Oeuvre $oeuvre, MediaFile $mediaFile, ConsultationAsset $asset, User $user): string
    {
        return URL::temporarySignedRoute(
            'admin.oeuvres.files.consult.asset',
            now()->addMinutes((int) config('vault.consult.url_ttl_minutes')),
            [
                'oeuvre' => $oeuvre->uuid,
                'mediaFile' => $mediaFile->uuid,
                'asset' => $asset->uuid,
                'admin' => $user->id,
            ],
        );
    }
}
