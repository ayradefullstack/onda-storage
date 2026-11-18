<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Review\ApprovalRefused;
use App\Actions\Review\ApproveOeuvre;
use App\Actions\Review\FilesNotAccepted;
use App\Actions\Review\OpenReview;
use App\Actions\Review\RejectOeuvre;
use App\Domain\Deposit\Exceptions\IllegalTransition;
use App\Domain\Deposit\Exceptions\ReasonRequired;
use App\Domain\Deposit\Exceptions\ReviewConflict;
use App\Domain\Deposit\OeuvreStatusMachine;
use App\Domain\Deposit\SubmissionGate;
use App\Domain\Deposit\Value\SubmissionReason;
use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Http\Controllers\Author\OeuvreController as AuthorOeuvreController;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The officer's three decisions. Thin: each validates its own input, calls
 * one action, and maps the domain's refusals onto HTTP.
 *
 * The `role:admin` boundary is the route group's (routes/admin.php); the
 * transition table's own actor check runs again inside
 * OeuvreStatusMachine, so a future route that forgets the middleware still
 * cannot register a deposit.
 *
 * Two callers, one set of rules. The admin pages call these with
 * `Accept: application/json` (the dialogs need the problem list and the
 * conflict's author and date, then reload their props in place); anything
 * else gets the redirect-and-flash an Inertia form expects.
 *
 * What a refusal means:
 *  - 409, a conflict — the officer did nothing wrong, someone else got
 *    there first. Either another officer holds the deposit (`held`: the
 *    page offers a take-over) or another officer already decided it
 *    (`decided`: the page says who and when, and refreshes).
 *  - 422, the deposit cannot go there — a rejection without a usable
 *    reason, a registration of an incomplete deposit (with the exact list
 *    of problems), or any move out of `registered`.
 *  - 200 with `replayed`, the same officer repeating the decision they
 *    already made (a double click, a retry after a timeout): the current
 *    state is returned and nothing is written twice.
 */
final class OeuvreReviewController extends Controller
{
    /** A reason the author can act on, not "non" — and not a novel. */
    private const REASON_MIN = 20;

    private const REASON_MAX = 2000;

    /** The decisions a summary can offer, in the order the dialog shows them. */
    private const OPEN_REVIEW = 'open_review';

    private const TAKE_OVER = 'take_over';

    private const APPROVE = 'approve';

    private const REJECT = 'reject';

    /**
     * Everything the review dialog shows, and the only place it learns
     * which decisions to offer.
     *
     * `allowed` is computed with the same rules the three POST routes
     * enforce: the policy, the status machine's transition table, the
     * holder model, and — for `approve` — SubmissionGate. A decision the
     * status and holder would permit but the gate refuses is listed in
     * `blocked` instead, so the dialog can show "Accepter" greyed with the
     * checklist as its reason rather than not at all.
     *
     * Advisory like every read: the POST re-decides under a row lock, and
     * the dialog refetches this after any refusal.
     */
    public function summary(Request $request, Oeuvre $oeuvre, SubmissionGate $gate, OeuvreStatusMachine $machine): JsonResponse
    {
        /** @var User $officer */
        $officer = $request->user();

        Gate::forUser($officer)->authorize('inspect', $oeuvre);

        $oeuvre->load([
            'author:id,name',
            'registerTypeCollege',
            'reviewer' => fn ($query) => $query->withTrashed()->select(['id', 'uuid', 'name']),
        ]);

        $verdict = $gate->evaluate($oeuvre);
        // The second registration rule: every file accepted by an officer
        // (ApproveOeuvre asks the same question under the lock).
        $notAccepted = ApproveOeuvre::filesNotAccepted($oeuvre);
        $underReview = $oeuvre->status === OeuvreStatus::UNDER_REVIEW;
        // `reviewed_by` outlives the review (it names the decider of a
        // registered or rejected deposit); it is a *holder* only while the
        // deposit is open.
        $holder = $underReview ? $oeuvre->reviewer : null;
        $heldByOther = $underReview && $oeuvre->reviewed_by !== null && $oeuvre->reviewed_by !== $officer->id;

        $allowed = [];
        $blocked = [];

        if ($officer->can('decide', $oeuvre)) {
            if ($machine->can($oeuvre, OeuvreStatus::UNDER_REVIEW, $officer)) {
                $allowed[] = self::OPEN_REVIEW;
            } elseif ($heldByOther) {
                $allowed[] = self::TAKE_OVER;
            } else {
                if ($machine->can($oeuvre, OeuvreStatus::REGISTERED, $officer)) {
                    if ($verdict->passes() && $notAccepted === []) {
                        $allowed[] = self::APPROVE;
                    } else {
                        $blocked[] = self::APPROVE;
                    }
                }

                if ($machine->can($oeuvre, OeuvreStatus::REJECTED, $officer)) {
                    $allowed[] = self::REJECT;
                }
            }
        }

        $names = fn (array $reasons, string ...$codes): array => array_values(array_map(
            fn (SubmissionReason $reason): string => (string) ($reason->params['name'] ?? ''),
            array_filter($reasons, fn (SubmissionReason $reason): bool => in_array($reason->code, $codes, true)),
        ));

        $notReady = [];

        foreach ($verdict->blockers as $reason) {
            $status = match ($reason->code) {
                SubmissionReason::FILE_IN_PIPELINE => (string) ($reason->params['status'] ?? ''),
                SubmissionReason::FILE_FAILED => MediaFileStatus::FAILED->key(),
                SubmissionReason::FILE_QUARANTINED => MediaFileStatus::QUARANTINED->key(),
                default => null,
            };

            if ($status !== null) {
                $notReady[] = ['name' => (string) ($reason->params['name'] ?? ''), 'status' => $status];
            }
        }

        $lastDecision = $oeuvre->oeuvreReviews()
            ->whereIn('to_status', [OeuvreStatus::REGISTERED, OeuvreStatus::REJECTED])
            ->reorder()
            ->latest('id')
            ->with(['actor' => fn ($query) => $query->withTrashed()->select(['id', 'name'])])
            ->first();

        return response()->json([
            'uuid' => $oeuvre->uuid,
            'status' => $oeuvre->status,
            'reference' => $oeuvre->uuid,
            'title' => $oeuvre->title,
            'author' => $oeuvre->author === null ? null : ['name' => $oeuvre->author->name],
            'college' => AuthorOeuvreController::collegeName($oeuvre),
            'createdAt' => $oeuvre->created_at?->toIso8601String(),
            'submittedAt' => $oeuvre->submitted_at?->toIso8601String(),
            'holder' => $holder === null ? null : ['id' => $holder->uuid, 'name' => $holder->name],
            'heldByMe' => $underReview && $oeuvre->reviewed_by === $officer->id,
            'checklist' => [
                'noFiles' => $names($verdict->blockers, SubmissionReason::NO_FILES) !== [],
                'requiredMissing' => $names($verdict->blockers, SubmissionReason::SLOT_EMPTY),
                'filesNotReady' => $notReady,
                'conditionalMissing' => $names($verdict->advisories, SubmissionReason::SLOT_EMPTY_CONDITIONAL),
                'filesNotAccepted' => $notAccepted,
                // Advisory, never blocking: slots whose rejected file the
                // author removed and has not replaced (or is still
                // uploading). A required one is already a requiredMissing
                // blocker; an optional one is the author's choice. null =
                // the unclassified oeuvre's single area.
                // A slot already named in requiredMissing is a blocker, not
                // an advisory: it is not repeated here.
                'openReplacementSlots' => $underReview
                    ? MediaFile::openReplacements($oeuvre->id, null, anySlot: true)
                        ->with('collegeOeuvreFile')
                        ->get(['media_files.id', 'college_oeuvre_file_id'])
                        ->map(fn (MediaFile $file): ?string => $file->collegeOeuvreFile === null ? null : trim($file->collegeOeuvreFile->title_global))
                        ->reject(fn (?string $label): bool => $label !== null && in_array($label, $names($verdict->blockers, SubmissionReason::SLOT_EMPTY), true))
                        ->unique()
                        ->values()
                        ->all()
                    : [],
            ],
            // For the reject screen's "Inclure les fichiers rejetés" chip.
            'rejectedFiles' => $oeuvre->mediaFiles()
                ->where('review_status', ReviewStatusEnum::REJECTED)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'original_name', 'review_reason'])
                ->map(fn (MediaFile $file): array => ['name' => $file->original_name, 'reason' => (string) $file->review_reason])
                ->values(),
            'allowed' => $allowed,
            'blocked' => $blocked,
            'lastDecision' => $lastDecision === null ? null : [
                'to' => $lastDecision->to_status,
                'by' => $lastDecision->actor?->name,
                'at' => $lastDecision->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function review(Request $request, Oeuvre $oeuvre, OpenReview $open): RedirectResponse|JsonResponse
    {
        Gate::forUser($request->user())->authorize('decide', $oeuvre);

        $validated = $request->validate([
            'take_over' => ['sometimes', 'boolean'],
        ]);

        return $this->decide($request, $oeuvre, OeuvreStatus::UNDER_REVIEW, fn () => $open->handle(
            $oeuvre,
            $request->user(),
            (bool) ($validated['take_over'] ?? false),
        ));
    }

    public function approve(Request $request, Oeuvre $oeuvre, ApproveOeuvre $approve): RedirectResponse|JsonResponse
    {
        Gate::forUser($request->user())->authorize('decide', $oeuvre);

        $validated = $request->validate([
            'take_over' => ['sometimes', 'boolean'],
        ]);

        return $this->decide(
            $request,
            $oeuvre,
            OeuvreStatus::REGISTERED,
            fn () => $approve->handle(
                $oeuvre,
                $request->user(),
                (bool) ($validated['take_over'] ?? false),
            ),
            __('The deposit has been registered.'),
        );
    }

    public function reject(Request $request, Oeuvre $oeuvre, RejectOeuvre $reject): RedirectResponse|JsonResponse
    {
        Gate::forUser($request->user())->authorize('decide', $oeuvre);

        // Validated here as well as in the machine: the length rule gives
        // the officer an inline field error, where the domain exception can
        // only be a page-level refusal. The machine stays authoritative on
        // "there must be one" — it is what a future API or console command
        // would hit. TrimStrings has already trimmed the input, so the
        // bounds count what the author will actually read.
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX],
            'take_over' => ['sometimes', 'boolean'],
        ]);

        return $this->decide(
            $request,
            $oeuvre,
            OeuvreStatus::REJECTED,
            fn () => $reject->handle(
                $oeuvre,
                $request->user(),
                $validated['reason'],
                (bool) ($validated['take_over'] ?? false),
            ),
            __('The author has been told why this deposit was rejected.'),
        );
    }

    /**
     * Run one decision and answer for it.
     *
     * @param  \Closure(): mixed  $decision
     */
    private function decide(Request $request, Oeuvre $oeuvre, OeuvreStatus $target, \Closure $decision, ?string $toast = null): RedirectResponse|JsonResponse
    {
        $replayed = false;

        try {
            $decision();
        } catch (ReviewConflict $e) {
            $this->conflict($request, $e->getMessage(), [
                'kind' => $e->holder === null ? 'moved' : 'held',
                'by' => $e->holder?->name,
                'at' => $oeuvre->fresh()?->reviewed_at?->toIso8601String(),
                'status' => $e->currentStatus ?? $oeuvre->fresh()?->status,
            ]);
        } catch (ApprovalRefused $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['approval' => $e->verdict->blockerMessages()],
                    // Code + parameters, rendered by the page in the
                    // officer's own language (there is no server-side lang/).
                    'problems' => $e->verdict->toArray()['blockers'],
                ], 422);
            }

            throw ValidationException::withMessages(['approval' => $e->verdict->blockerMessages()]);
        } catch (FilesNotAccepted $e) {
            $messages = array_map(fn (array $file): string => "{$file['name']} ({$file['reviewStatus']})", $e->files);

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['approval' => $messages],
                    'problems' => array_map(fn (array $file): array => [
                        'code' => 'file_not_accepted',
                        'params' => ['name' => $file['name'], 'reviewStatus' => $file['reviewStatus']],
                        'message' => "{$file['name']} has not been accepted.",
                    ], $e->files),
                    'filesNotAccepted' => $e->files,
                ], 422);
            }

            throw ValidationException::withMessages(['approval' => $messages]);
        } catch (ReasonRequired $e) {
            throw ValidationException::withMessages(['reason' => [$e->getMessage()]]);
        } catch (IllegalTransition $e) {
            $replayed = $this->replayOrRefuse($request, $oeuvre, $target, $e);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'status' => $oeuvre->fresh()?->status,
                'replayed' => $replayed,
            ]);
        }

        if ($toast !== null && ! $replayed) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $toast]);
        }

        return back();
    }

    /**
     * The machine refused the move. Three different things can be behind
     * that, told apart by reading what is actually committed now — the
     * machine checked under a row lock, so this is never a guess:
     *
     *  - the deposit is already where this officer wanted it, by their own
     *    hand: a repeat. Returns true; nothing was written.
     *  - another officer decided it (registered or rejected) in the
     *    meantime: 409, naming them and when.
     *  - anything else — above all, any move out of `registered`: 422. It
     *    is 422 rather than 403: the officer has the right role, the
     *    deposit simply cannot go there.
     *
     * @throws ValidationException
     * @throws HttpResponseException
     */
    private function replayOrRefuse(Request $request, Oeuvre $oeuvre, OeuvreStatus $target, IllegalTransition $refusal): bool
    {
        /** @var User $officer */
        $officer = $request->user();
        $current = $oeuvre->fresh();

        if ($current !== null) {
            [$decider, $decidedAt] = $this->lastDecision($current);
            $decidedByOfficer = $decider !== null && $decider->id === $officer->id;

            if ($current->status === $target && $decidedByOfficer) {
                return true;
            }

            $isDecided = in_array($current->status, [OeuvreStatus::REGISTERED, OeuvreStatus::REJECTED], true);

            if ($isDecided && $decider !== null && ! $decidedByOfficer) {
                $this->conflict($request, "{$decider->name} has already decided on this deposit.", [
                    'kind' => 'decided',
                    'by' => $decider->name,
                    'at' => $decidedAt?->toIso8601String(),
                    'status' => $current->status,
                ]);
            }
        }

        throw ValidationException::withMessages(['status' => [$refusal->getMessage()]]);
    }

    /**
     * Who put the deposit in its current status, and when: the newest
     * history row that landed there. `reviewed_by` is the fallback for a
     * deposit whose history predates `oeuvre_reviews`.
     *
     * @return array{0: User|null, 1: Carbon|null}
     */
    private function lastDecision(Oeuvre $oeuvre): array
    {
        $review = $oeuvre->oeuvreReviews()
            ->where('to_status', $oeuvre->status)
            ->reorder()
            ->latest('id')
            ->with(['actor' => fn ($query) => $query->withTrashed()])
            ->first();

        if ($review !== null) {
            return [$review->actor, $review->created_at];
        }

        return [$oeuvre->reviewer()->withTrashed()->first(), $oeuvre->reviewed_at];
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
