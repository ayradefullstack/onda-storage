<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Support\Collection;

/**
 * "4 / 6" for a whole page of oeuvres, in two queries total.
 *
 * `Oeuvre::requiredDocumentsProgress()` answers the same question for one
 * oeuvre and costs two queries doing it. Called per row on a 25-row table
 * that is 50 queries, and it grows with the page size — exactly the N+1
 * the works table must not have. This is the Query Object CLAUDE.md allows
 * "where a query is genuinely complex": one query for the collèges'
 * requirement definitions, one for the satisfied slots across every oeuvre
 * on the page, both keyed in PHP afterwards.
 *
 * The counting rules are the same ones the rest of the deposit flow uses,
 * and they must stay that way — if they drift, the table and the submit
 * button disagree about whether a deposit is complete:
 *
 *  - only a file at `ready` satisfies a slot (a scanning or failed one
 *    satisfies nothing);
 *  - a soft-deleted file satisfies nothing;
 *  - `conditional` counts the unsatisfied required slots that carry a
 *    (still unevaluated) `conditions` expression, so the page can say
 *    "may not apply" rather than presenting `total` as a hard target.
 *    See SubmissionGate for why those do not block.
 */
final class SlotProgressQuery
{
    /**
     * @param  Collection<int, Oeuvre>  $oeuvres  one page of rows, straight off the paginator
     * @return array<int, array{satisfied: int, total: int, conditional: int}> keyed by oeuvre id
     */
    public function forPage(Collection $oeuvres): array
    {
        /** @var array<int, array{satisfied: int, total: int, conditional: int}> $empty */
        $empty = [];

        foreach ($oeuvres as $oeuvre) {
            $empty[$oeuvre->id] = ['satisfied' => 0, 'total' => 0, 'conditional' => 0];
        }

        $collegeIds = $oeuvres->pluck('register_type_college_id')->filter()->unique()->values();

        if ($collegeIds->isEmpty()) {
            return $empty;
        }

        // Query 1: every required slot of every collège represented on this
        // page. Retired slots are excluded by the SoftDeletes scope, as
        // Oeuvre::requirements() does.
        $requirements = CollegeOeuvreFile::query()
            ->whereIn('register_type_college_id', $collegeIds)
            ->where('is_required', true)
            ->get(['id', 'register_type_college_id', 'conditions'])
            ->groupBy('register_type_college_id');

        // Query 2: which (oeuvre, slot) pairs hold a `ready` file.
        $satisfied = MediaFile::query()
            ->whereIn('oeuvre_id', $oeuvres->pluck('id')->all())
            ->where('status', MediaFileStatus::READY)
            ->whereNotNull('college_oeuvre_file_id')
            ->distinct()
            ->get(['oeuvre_id', 'college_oeuvre_file_id'])
            ->groupBy('oeuvre_id')
            ->map(fn (Collection $rows): array => $rows
                ->pluck('college_oeuvre_file_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());

        $progress = $empty;

        foreach ($oeuvres as $oeuvre) {
            $collegeId = $oeuvre->register_type_college_id;

            if ($collegeId === null) {
                continue;
            }

            /** @var Collection<int, CollegeOeuvreFile> $required */
            $required = $requirements->get($collegeId) ?? collect();
            $satisfiedIds = $satisfied->get($oeuvre->id) ?? [];

            $unsatisfied = $required->reject(
                fn (CollegeOeuvreFile $requirement): bool => in_array($requirement->id, $satisfiedIds, true)
            );

            $progress[$oeuvre->id] = [
                'satisfied' => $required->count() - $unsatisfied->count(),
                'total' => $required->count(),
                'conditional' => $unsatisfied
                    ->filter(fn (CollegeOeuvreFile $requirement): bool => $requirement->conditions !== null)
                    ->count(),
            ];
        }

        return $progress;
    }
}
