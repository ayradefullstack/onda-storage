<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Review\DecideMediaFile;
use App\Actions\Review\FileDecisionRefused;
use App\Domain\Deposit\Exceptions\ReasonRequired;
use App\Domain\Deposit\Exceptions\ReviewConflict;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * POST admin/oeuvres/{oeuvre}/files/{mediaFile}/review — the officer's
 * decision on one file. Thin, like OeuvreReviewController: validate, call
 * DecideMediaFile, map its refusals onto HTTP.
 *
 *  - 403  not an admin (route group + MediaFilePolicy::review)
 *  - 404  the file is not this oeuvre's (scoped binding)
 *  - 409  another officer holds the deposit (`held`, names them), or the
 *         deposit was already decided (`decided`, who and when)
 *  - 422  an unknown decision key, a rejection reason outside 20–2000
 *         characters, a deposit not under review, or a file whose
 *         pipeline state does not allow the decision (exact message)
 *  - 200  with `replayed: true` for an identical repeat (nothing written)
 *
 * JSON for the admin pages (`Accept: application/json`), redirect + flash
 * for a plain form post.
 */
final class MediaFileReviewController extends Controller
{
    private const REASON_MIN = 20;

    private const REASON_MAX = 2000;

    public function store(Request $request, Oeuvre $oeuvre, MediaFile $mediaFile, DecideMediaFile $decide): JsonResponse|RedirectResponse
    {
        /** @var User $officer */
        $officer = $request->user();

        Gate::forUser($officer)->authorize('review', $mediaFile);

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(ReviewStatusEnum::keys())],
            // TrimStrings has already trimmed it: the bounds count what the author reads.
            'reason' => ['nullable', 'string', 'required_if:decision,'.ReviewStatusEnum::REJECTED->key(), 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX],
        ]);

        /** @var ReviewStatusEnum $target validated above */
        $target = ReviewStatusEnum::fromKey($validated['decision']);
        $reason = $target === ReviewStatusEnum::REJECTED ? ($validated['reason'] ?? null) : null;

        try {
            $result = $decide->handle($mediaFile, $target, $officer, $reason);
        } catch (ReviewConflict $e) {
            $this->refuseConflict($request, $oeuvre, $e);
        } catch (FileDecisionRefused $e) {
            throw ValidationException::withMessages(['decision' => [$e->getMessage()]]);
        } catch (ReasonRequired $e) {
            throw ValidationException::withMessages(['reason' => [$e->getMessage()]]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'reviewStatus' => $result['file']->review_status,
                'replayed' => $result['replayed'],
            ]);
        }

        if (! $result['replayed']) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('The file decision has been recorded.')]);
        }

        return back();
    }

    /**
     * A conflict is a 409 only when someone else got there first: another
     * holder, or a decision on the deposit. A deposit that simply is not
     * under review (draft, submitted) is a 422 — nobody to name.
     *
     * @throws HttpResponseException
     * @throws ValidationException
     */
    private function refuseConflict(Request $request, Oeuvre $oeuvre, ReviewConflict $e): never
    {
        $current = $oeuvre->fresh() ?? $oeuvre;

        if ($e->holder !== null) {
            $this->conflict($request, $e->getMessage(), [
                'kind' => 'held',
                'by' => $e->holder->name,
                'at' => $current->reviewed_at?->toIso8601String(),
                'status' => $current->status,
            ]);
        }

        if (in_array($current->status, [OeuvreStatus::REGISTERED, OeuvreStatus::REJECTED], true)) {
            $decision = $current->oeuvreReviews()
                ->where('to_status', $current->status)
                ->reorder()
                ->latest('id')
                ->with(['actor' => fn ($query) => $query->withTrashed()])
                ->first();
            $by = $decision->actor->name ?? $current->reviewer()->withTrashed()->first()?->name;
            $at = $decision->created_at ?? $current->reviewed_at;

            $this->conflict($request, ($by ?? 'ONDA').' has already decided on this deposit.', [
                'kind' => 'decided',
                'by' => $by,
                'at' => $at?->toIso8601String(),
                'status' => $current->status,
            ]);
        }

        throw ValidationException::withMessages(['decision' => ['This deposit is not under review; open it for review before deciding on its files.']]);
    }

    /**
     * @param  array{kind: string, by: string|null, at: string|null, status: OeuvreStatus|null}  $conflict
     *
     * @throws HttpResponseException
     */
    private function conflict(Request $request, string $message, array $conflict): never
    {
        if ($request->wantsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => $message,
                'conflict' => $conflict,
            ], 409));
        }

        abort(409, $message);
    }
}
