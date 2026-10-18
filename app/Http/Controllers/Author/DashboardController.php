<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Upload\InitUpload;
use App\Domain\Deposit\SubmissionGate;
use App\Domain\Deposit\Value\SubmissionReason;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Http\Controllers\Controller;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\StorageQuota;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The author's home page. Every number and row on it is read here, scoped
 * to the signed-in author — the page holds no data of its own.
 *
 * Nothing is re-derived: the counts are the works table's own
 * (OeuvreController::statusCounts), "what is missing" is SubmissionGate's
 * verdict, the decisions are `oeuvre_reviews` rows, and storage is the same
 * `used_bytes` + live reservations InitUpload checks against.
 *
 * Query budget — fixed, whatever the size of the shelf:
 *   1   status counts
 *   2   actionable oeuvres + their collège
 *   1   latest rejection of the rejected ones
 *   ≤10 SubmissionGate, 2 per actionable oeuvre, at most ACTION_LIMIT of them
 *   2   recent oeuvres + their collège
 *   2   quota row + live upload reservations
 *   3   decisions + their oeuvre + its collège
 * An author with no oeuvre at all stops after the first query.
 */
final class DashboardController extends Controller
{
    /** The gate costs two queries per oeuvre, so the list is capped. */
    private const ACTION_LIMIT = 5;

    private const RECENT_LIMIT = 5;

    private const DECISION_LIMIT = 3;

    /**
     * The transitions an author is told about: the ONDA opened, accepted or
     * refused their deposit. Their own submissions are not decisions.
     *
     * @var list<OeuvreStatus>
     */
    private const DECISIONS = [OeuvreStatus::UNDER_REVIEW, OeuvreStatus::REGISTERED, OeuvreStatus::REJECTED];

    public function __invoke(Request $request, SubmissionGate $gate): Response
    {
        /** @var User $user */
        $user = $request->user();
        $counts = OeuvreController::statusCounts($user->id);

        if ($counts['all'] === 0) {
            return Inertia::render('author/Dashboard', [
                'counts' => $counts,
                'actions' => ['items' => [], 'total' => 0],
                'recent' => [],
                'quota' => null,
                'decisions' => [],
            ]);
        }

        return Inertia::render('author/Dashboard', [
            'counts' => $counts,
            'actions' => [
                'items' => $this->actions($user, $gate),
                // Every deposit still in the author's hands, shown or not.
                'total' => $counts[OeuvreStatus::DRAFT->key()] + $counts[OeuvreStatus::REJECTED->key()],
            ],
            'recent' => $this->recent($user),
            'quota' => $this->quota($user),
            'decisions' => $this->decisions($user),
        ]);
    }

    /**
     * The deposits the author can act on — drafts and rejected ones, the
     * statuses OeuvrePolicy::submit() accepts — rejected first, since those
     * are the ones the ONDA is waiting on.
     *
     * @return array<int, array<string, mixed>>
     */
    private function actions(User $user, SubmissionGate $gate): array
    {
        $oeuvres = Oeuvre::query()
            ->where('author_id', $user->id)
            ->whereIn('status', OeuvreStatus::authorEditable())
            ->with('registerTypeCollege')
            ->orderByRaw('status = ? desc', [OeuvreStatus::REJECTED])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::ACTION_LIMIT)
            ->get();

        $rejections = $this->latestRejections(
            $oeuvres->where('status', OeuvreStatus::REJECTED)->map(fn (Oeuvre $oeuvre): int => $oeuvre->id)->values()->all()
        );

        return $oeuvres->map(function (Oeuvre $oeuvre) use ($user, $gate, $rejections): array {
            // The real gate, the one the submit POST runs — never an
            // approximation of it.
            $verdict = $gate->evaluate($oeuvre);
            $rejection = $rejections[$oeuvre->id] ?? null;

            return [
                'uuid' => $oeuvre->uuid,
                'label_source' => self::labelSource($oeuvre),
                'status' => $oeuvre->status,
                'kind' => match (true) {
                    $oeuvre->status === OeuvreStatus::REJECTED => 'rejected',
                    $verdict->passes() && $user->can('submit', $oeuvre) => 'ready',
                    default => 'incomplete',
                },
                'blockers' => array_map(
                    fn (SubmissionReason $reason): array => ['code' => $reason->code, 'params' => $reason->params],
                    $verdict->blockers,
                ),
                'rejection' => $rejection === null ? null : [
                    'reason' => $rejection->reason,
                    'at' => $rejection->created_at?->toIso8601String(),
                ],
            ];
        })->values()->all();
    }

    /**
     * The newest rejection of each oeuvre, in one query. Who rejected is
     * not read: the author is answered by the ONDA, never by an officer.
     *
     * @param  array<int, int>  $oeuvreIds
     * @return array<int, OeuvreReview>
     */
    private function latestRejections(array $oeuvreIds): array
    {
        if ($oeuvreIds === []) {
            return [];
        }

        $latest = [];

        $rows = OeuvreReview::query()
            ->whereIn('oeuvre_id', $oeuvreIds)
            ->where('to_status', OeuvreStatus::REJECTED)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'oeuvre_id', 'reason', 'created_at']);

        foreach ($rows as $row) {
            $latest[$row->oeuvre_id] ??= $row;
        }

        return $latest;
    }

    /**
     * The five oeuvres the author touched last. `oeuvres.updated_at` alone
     * does not move when a file is added or finishes processing, so the
     * newest of it and the oeuvre's files' `updated_at` is used — inside
     * the same statement, not per row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recent(User $user): array
    {
        $files = '(select max(mf.updated_at) from media_files mf where mf.oeuvre_id = oeuvres.id)';

        return Oeuvre::query()
            ->where('author_id', $user->id)
            ->with('registerTypeCollege')
            ->select('oeuvres.*')
            ->selectRaw("case when {$files} > oeuvres.updated_at then {$files} else oeuvres.updated_at end as last_activity_at")
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (Oeuvre $oeuvre): array => [
                'uuid' => $oeuvre->uuid,
                'label_source' => self::labelSource($oeuvre),
                'status' => $oeuvre->status,
                'last_activity_at' => self::iso($oeuvre->getAttribute('last_activity_at')),
            ])
            ->values()
            ->all();
    }

    /**
     * Storage as InitUpload counts it: `used_bytes` plus what the author's
     * live upload sessions have reserved, against `limit_bytes`.
     *
     * Null without a quota row. Until a first upload completes and
     * CompleteUpload creates that row, InitUpload enforces no limit at all
     * (QuotaPolicy::canAccept(null)), so showing one would be untrue.
     *
     * @return array{used_bytes: int, reserved_bytes: int, limit_bytes: int}|null
     */
    private function quota(User $user): ?array
    {
        $quota = StorageQuota::where('user_id', $user->id)->first();

        if ($quota === null) {
            return null;
        }

        return [
            'used_bytes' => $quota->used_bytes,
            'reserved_bytes' => (int) InitUpload::liveSessions($user->id)->sum('size_bytes'),
            'limit_bytes' => $quota->limit_bytes,
        ];
    }

    /**
     * The three newest ONDA decisions on the author's oeuvres. The actor is
     * neither loaded nor sent (see OeuvreController::reviewHistory()).
     *
     * @return array<int, array<string, mixed>>
     */
    private function decisions(User $user): array
    {
        return OeuvreReview::query()
            ->whereIn('to_status', self::DECISIONS)
            ->whereHas('oeuvre', fn ($query) => $query->where('author_id', $user->id))
            ->with('oeuvre.registerTypeCollege')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::DECISION_LIMIT)
            ->get(['id', 'uuid', 'oeuvre_id', 'to_status', 'created_at'])
            ->map(fn (OeuvreReview $review): array => [
                'uuid' => $review->uuid,
                'oeuvre_uuid' => $review->oeuvre->uuid,
                'label_source' => self::labelSource($review->oeuvre),
                'to_status' => $review->to_status,
                'at' => $review->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * What `components/oeuvre/label.ts` needs to name a deposit the way the
     * works table does.
     *
     * @return array{uuid: string, title: string|null, college_name: string|null, created_at: string|null}
     */
    private static function labelSource(Oeuvre $oeuvre): array
    {
        return [
            'uuid' => $oeuvre->uuid,
            'title' => $oeuvre->title,
            'college_name' => OeuvreController::collegeName($oeuvre),
            'created_at' => $oeuvre->created_at?->toIso8601String(),
        ];
    }

    private static function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse((string) $value)->toIso8601String();
    }
}
