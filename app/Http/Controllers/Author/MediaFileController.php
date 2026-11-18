<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Oeuvre\RemovalConflict;
use App\Actions\Oeuvre\RemoveMediaFile;
use App\Domain\Deposit\Exceptions\RemovalRefused;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Removing a file from a deposit — the only mutation this controller has.
 *
 * There is deliberately no update/store here: adding files is the chunked
 * upload path (UploadController), and nothing anywhere changes an existing
 * oeuvre's classification.
 *
 * Two callers. The draft/rejected page uses an Inertia form (redirect and
 * flash). The under-review page removes a rejected file with
 * `Accept: application/json`, because it needs to tell a race apart:
 *  - 409 `file_no_longer_rejected` — an officer just revised the decision;
 *  - 409 `oeuvre_decided` — the deposit was registered or rejected meanwhile;
 *  - 422 — the server's own message (e.g. a file still mid-pipeline);
 *  - 200 — removed, or already removed (a repeat changes nothing).
 */
final class MediaFileController extends Controller
{
    public function destroy(Request $request, MediaFile $mediaFile, RemoveMediaFile $remove): RedirectResponse|JsonResponse
    {
        // Loaded before the policy runs: the policy reads the owning
        // oeuvre's status, and letting it lazy-load inside the gate hides
        // a query behind an authorization check.
        $mediaFile->loadMissing('oeuvre');

        // Under review, a file that WAS rejected but no longer is (the officer
        // just revised the decision) is a race, not a forbidden act: 409, so
        // the page can say so and refresh. A file never rejected stays a 403.
        // Likewise a rejected file whose deposit the ONDA has just registered:
        // the page was loaded under review, the decision landed since.
        $conflict = match (true) {
            $request->user()->id !== $mediaFile->oeuvre->author_id => null,
            // The author's replacement, accepted by the officer since the
            // page loaded: part of the record now.
            $mediaFile->oeuvre->status === OeuvreStatus::UNDER_REVIEW
                && $mediaFile->replaces_media_file_id !== null
                && $mediaFile->review_status === ReviewStatusEnum::ACCEPTED => RemovalConflict::notRemovable(),
            $mediaFile->oeuvre->status === OeuvreStatus::UNDER_REVIEW
                && $mediaFile->replaces_media_file_id === null
                && $mediaFile->review_status !== ReviewStatusEnum::REJECTED
                && $mediaFile->reviews()->where('to_status', ReviewStatusEnum::REJECTED)->exists() => RemovalConflict::noLongerRejected(),
            $mediaFile->oeuvre->status === OeuvreStatus::REGISTERED
                && $mediaFile->review_status === ReviewStatusEnum::REJECTED => RemovalConflict::oeuvreDecided(),
            default => null,
        };

        if ($conflict !== null) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $conflict->getMessage(), 'error' => $conflict->kind], 409);
            }

            abort(409, $conflict->getMessage());
        }

        Gate::forUser($request->user())->authorize('delete', $mediaFile);

        try {
            $remove->handle($mediaFile);
        } catch (RemovalRefused $e) {
            // 422, not 403: the author is allowed to remove this file —
            // just not while its pipeline is still running over it.
            throw ValidationException::withMessages([
                'media_file' => [$e->getMessage()],
            ]);
        } catch (RemovalConflict $e) {
            if ($request->wantsJson()) {
                return response()->json(['message' => $e->getMessage(), 'error' => $e->kind], 409);
            }

            abort(409, $e->getMessage());
        }

        if ($request->wantsJson()) {
            return response()->json(['uuid' => $mediaFile->uuid, 'removed' => true]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The file has been removed from this work.')]);

        return back();
    }
}
