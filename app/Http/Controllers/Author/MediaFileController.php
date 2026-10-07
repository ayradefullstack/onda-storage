<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Oeuvre\RemoveMediaFile;
use App\Domain\Deposit\Exceptions\RemovalRefused;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
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
 */
final class MediaFileController extends Controller
{
    public function destroy(Request $request, MediaFile $mediaFile, RemoveMediaFile $remove): RedirectResponse
    {
        // Loaded before the policy runs: the policy reads the owning
        // oeuvre's status, and letting it lazy-load inside the gate hides
        // a query behind an authorization check.
        $mediaFile->loadMissing('oeuvre');

        Gate::forUser($request->user())->authorize('delete', $mediaFile);

        try {
            $remove->handle($mediaFile);
        } catch (RemovalRefused $e) {
            // 422, not 403: the author is allowed to remove this file —
            // just not while its pipeline is still running over it.
            throw ValidationException::withMessages([
                'media_file' => [$e->getMessage()],
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The file has been removed from this work.')]);

        return back();
    }
}
