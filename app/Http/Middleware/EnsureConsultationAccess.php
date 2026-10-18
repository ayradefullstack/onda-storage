<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\MediaConsultation\StatusEnum as ConsultationStatus;
use App\Infrastructure\Render\DeliveryMode;
use App\Infrastructure\Render\PreviewFamilyResolver;
use App\Models\MediaConsultation;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for a signed consultation-asset URL. By the time this runs the route
 * group has already required: authenticated, verified, role admin (or role
 * author on the owner's routes); and the `signed` middleware has verified the
 * signature and expiry. Left to check:
 *
 *  - the `admin` (admin routes) or `u` (the owning author's routes) parameter
 *    inside the signature is THIS session's user (a URL copied into another
 *    session fails),
 *  - the oeuvre is consultable: an admin by the `inspect` rule of the oeuvre
 *    page, an author only on their OWN oeuvre (`preview`; a 404 otherwise),
 *  - the media file's consultation is `ready`,
 *  - for the consult-ORIGINAL route only: the family's delivery mode is
 *    `original_inline` (404 otherwise — checked before `ready`, so a file
 *    configured `derivative` / `unsupported` never reveals its state there).
 *
 * Belonging (file in oeuvre, asset in file) is enforced by scoped route
 * binding and by the controller resolving the asset through the file.
 */
final class EnsureConsultationAccess
{
    public function __construct(private readonly PreviewFamilyResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null, 403);

        // The issuing user's id is inside the signature: `admin` on the admin
        // routes, `u` on the owner's. A URL replayed in another session fails.
        $admin = $user->hasRole('admin');

        abort_unless((int) $request->query($admin ? 'admin' : 'u') === $user->id, 403, 'This link was issued to a different session.');

        $oeuvre = $request->route('oeuvre');
        $mediaFile = $request->route('mediaFile');

        abort_unless($oeuvre instanceof Oeuvre && $mediaFile instanceof MediaFile, 404);

        if ($admin) {
            Gate::forUser($user)->authorize('inspect', $oeuvre);
        } else {
            // The owner only. Anyone else gets a 404, never a 403, so a probe
            // cannot confirm that the file exists.
            abort_unless(Gate::forUser($user)->allows('preview', $oeuvre), 404);
        }

        if (str_ends_with((string) $request->route()?->getName(), 'files.consult.original')) {
            abort_unless($this->resolver->modeFor($mediaFile) === DeliveryMode::OriginalInline, 404);
        }

        $ready = MediaConsultation::where('media_file_id', $mediaFile->id)
            ->where('status', ConsultationStatus::READY)
            ->exists();

        abort_unless($ready, 403, 'This preview is not ready.');

        return $next($request);
    }
}
