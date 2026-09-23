<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Actions\Media\IssueStreamUrl;
use App\Domain\Deposit\MediaFileStatus;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Issues a fresh, short-lived signed link to a `ready` deposit — the only
 * thing the frontend needs before opening a preview. Ownership is checked
 * here too, not only inside `StreamController`: no reason to hand out a
 * link (even a soon-to-expire one) for a file the requester can't view.
 */
final class StreamLinkController extends Controller
{
    public function __invoke(Request $request, MediaFile $mediaFile, IssueStreamUrl $issue): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $mediaFile);

        if ($mediaFile->status !== MediaFileStatus::READY) {
            abort(404);
        }

        return response()->json([
            'url' => $issue->handle($mediaFile, $request->user()),
        ]);
    }
}
