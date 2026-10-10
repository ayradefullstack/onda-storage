<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\MediaConsultation;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for a signed consultation-asset URL. By the time this runs the route
 * group has already required: authenticated, verified, role admin; and the
 * `signed` middleware has verified the signature and expiry. Left to check:
 *
 *  - the `admin` parameter inside the signature is THIS session's user (a URL
 *    copied into another admin's session fails),
 *  - the oeuvre is consultable (the same `inspect` rule as the oeuvre page),
 *  - the media file's consultation is `ready`.
 *
 * Belonging (file in oeuvre, asset in file) is enforced by scoped route
 * binding and by the controller resolving the asset through the file.
 */
final class EnsureConsultationAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->hasRole('admin'), 403);
        abort_unless((int) $request->query('admin') === $user->id, 403, 'This link was issued to a different session.');

        $oeuvre = $request->route('oeuvre');
        $mediaFile = $request->route('mediaFile');

        abort_unless($oeuvre instanceof Oeuvre && $mediaFile instanceof MediaFile, 404);

        Gate::forUser($user)->authorize('inspect', $oeuvre);

        $ready = MediaConsultation::where('media_file_id', $mediaFile->id)
            ->where('status', MediaConsultation::READY)
            ->exists();

        abort_unless($ready, 403, 'This preview is not ready.');

        return $next($request);
    }
}
