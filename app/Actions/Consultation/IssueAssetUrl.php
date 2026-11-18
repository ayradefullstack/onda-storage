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
 * user: `admin` (an admin) or `u` (the owning author) (their id) is inside the signature, and the middleware
 * rejects the request unless it equals the authenticated user. A copied URL
 * is therefore useless in another session, and a signature alone — a bearer
 * token — is never enough.
 */
final class IssueAssetUrl
{
    public function handle(Oeuvre $oeuvre, MediaFile $mediaFile, ConsultationAsset $asset, User $user): string
    {
        $admin = $user->hasRole('admin');

        return URL::temporarySignedRoute(
            $admin ? 'admin.oeuvres.files.consult.asset' : 'oeuvres.files.consult.asset',
            now()->addMinutes((int) config('vault.consult.url_ttl_minutes')),
            [
                'oeuvre' => $oeuvre->uuid,
                'mediaFile' => $mediaFile->uuid,
                'asset' => $asset->uuid,
                // `admin` on the admin route (unchanged), `u` on the owner's.
                $admin ? 'admin' : 'u' => $user->id,
            ],
        );
    }
}
