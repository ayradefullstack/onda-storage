<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

use App\Domain\Deposit\Value\SubmissionReason;
use App\Domain\Deposit\Value\SubmissionVerdict;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use Illuminate\Database\Eloquent\Collection;

/**
 * Decides whether an oeuvre may leave `draft`/`rejected` for `submitted`,
 * and returns *every* reason it may not.
 *
 * ---------------------------------------------------------------------
 * The conditions rule — read this before tightening the gate.
 * ---------------------------------------------------------------------
 * `college_oeuvre_files.conditions` holds ONDA's own show_when/hide_when
 * expression verbatim. It is stored but NOT evaluated: the declaration
 * fields it tests (was a sample used? is there a co-author?) do not exist
 * in this schema yet.
 *
 * MUSIQUE marks `autorisation_sample` as `is_required`, but ONDA only ever
 * asks for it when a sample was actually used. If this gate demanded every
 * `is_required` slot, an author who used no sample could never submit —
 * the button would be permanently dead with no way out.
 *
 * So the gate splits required slots by whether they carry a condition:
 *
 *   - required AND `conditions` IS NULL  -> BLOCKING. Must hold a `ready` file.
 *   - required AND `conditions` present  -> ADVISORY. Does not block; the
 *     officer is shown the empty ones at review time and judges whether
 *     they applied to this work.
 *
 * This tightens automatically the day `conditions` is evaluated: at that
 * point a conditional slot that evaluates to *applicable* simply becomes
 * blocking, and the only change needed here is to ask the evaluator
 * instead of testing for null.
 */
final class SubmissionGate
{
    /**
     * A file the pipeline has not finished with. Submitting mid-pipeline
     * would hand an officer a deposit whose files are still being checked.
     *
     * @var list<string>
     */
    private const IN_PIPELINE = [
        MediaFileStatus::UPLOADING,
        MediaFileStatus::ASSEMBLING,
        MediaFileStatus::SCANNING,
        MediaFileStatus::PROCESSING,
    ];

    /**
     * A file the author must resolve or remove before submitting — and
     * must be told about by name, since "a file failed" is useless when
     * the deposit holds nine of them.
     *
     * @var array<string, string>
     */
    private const UNRESOLVED = [
        MediaFileStatus::FAILED => SubmissionReason::FILE_FAILED,
        MediaFileStatus::QUARANTINED => SubmissionReason::FILE_QUARANTINED,
    ];

    public function evaluate(Oeuvre $oeuvre): SubmissionVerdict
    {
        /** @var list<SubmissionReason> $blockers */
        $blockers = [];

        $files = $oeuvre->mediaFiles()
            ->orderBy('created_at')
            ->get(['id', 'college_oeuvre_file_id', 'original_name', 'status']);

        if ($files->isEmpty()) {
            $blockers[] = new SubmissionReason(SubmissionReason::NO_FILES);
        }

        foreach ($files as $file) {
            if (in_array($file->status, self::IN_PIPELINE, true)) {
                $blockers[] = new SubmissionReason(SubmissionReason::FILE_IN_PIPELINE, [
                    'name' => $file->original_name,
                    'status' => $file->status,
                ]);

                continue;
            }

            if (isset(self::UNRESOLVED[$file->status])) {
                $blockers[] = new SubmissionReason(self::UNRESOLVED[$file->status], [
                    'name' => $file->original_name,
                ]);
            }
        }

        [$slotBlockers, $advisories] = $this->evaluateSlots($oeuvre, $files);

        return new SubmissionVerdict(
            blockers: [...$blockers, ...$slotBlockers],
            advisories: $advisories,
        );
    }

    /**
     * @param  Collection<int, MediaFile>  $files
     * @return array{0: list<SubmissionReason>, 1: list<SubmissionReason>}
     */
    private function evaluateSlots(Oeuvre $oeuvre, $files): array
    {
        if ($oeuvre->register_type_college_id === null) {
            // Filed before classification existed: it has no slots at all,
            // so there is nothing to satisfy. The file checks above still
            // apply.
            return [[], []];
        }

        $satisfiedSlotIds = $files
            ->where('status', MediaFileStatus::READY)
            ->pluck('college_oeuvre_file_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        /** @var list<SubmissionReason> $blockers */
        $blockers = [];
        /** @var list<SubmissionReason> $advisories */
        $advisories = [];

        foreach ($oeuvre->requirements()->required()->get() as $requirement) {
            if (in_array($requirement->id, $satisfiedSlotIds, true)) {
                continue;
            }

            $conditional = $this->isConditional($requirement);

            $reason = new SubmissionReason(
                $conditional ? SubmissionReason::SLOT_EMPTY_CONDITIONAL : SubmissionReason::SLOT_EMPTY,
                [
                    'name' => trim($requirement->title_global),
                    'document_key' => $requirement->document_key,
                    'slot_id' => $requirement->id,
                ],
            );

            if ($conditional) {
                $advisories[] = $reason;
            } else {
                $blockers[] = $reason;
            }
        }

        return [$blockers, $advisories];
    }

    /**
     * See the class docblock. When `conditions` becomes evaluable, this is
     * the single method that changes: it asks the evaluator whether the
     * condition holds for this oeuvre instead of merely noting that one
     * was recorded.
     */
    private function isConditional(CollegeOeuvreFile $requirement): bool
    {
        return $requirement->conditions !== null;
    }
}
