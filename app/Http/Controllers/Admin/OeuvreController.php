<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Access\AccessLogger;
use App\Domain\Deposit\OeuvreStatus;
use App\Domain\Deposit\SubmissionGate;
use App\Http\Controllers\Author\OeuvreController as AuthorOeuvreController;
use App\Http\Controllers\Concerns\ResolvesPerPage;
use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The other half of the review console: every submitted work across every
 * author (see AuthorController's docblock for the aggregate-query
 * rationale, which this mirrors).
 *
 * A `draft` is an author's private working state, never a submission. It is
 * hidden from the default list by the `excludingDrafts` scope (in the query,
 * not the Vue template) and appears only when the status filter is explicitly
 * set to `draft`. It is always read-only for an admin: see
 * OeuvrePolicy::decide().
 */
final class OeuvreController extends Controller
{
    use ResolvesPerPage;

    private const PER_PAGE = 25;

    /**
     * What the status filter offers. `draft` is last and only ever shown
     * when selected explicitly — see class docblock.
     *
     * @var list<string>
     */
    private const FILTERABLE_STATUSES = [
        OeuvreStatus::SUBMITTED,
        OeuvreStatus::UNDER_REVIEW,
        OeuvreStatus::REGISTERED,
        OeuvreStatus::REJECTED,
        OeuvreStatus::DRAFT,
    ];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status');
        $author = trim((string) $request->string('author'));
        $from = trim((string) $request->string('from'));
        $to = trim((string) $request->string('to'));

        $oeuvres = Oeuvre::query()
            ->when($status !== OeuvreStatus::DRAFT, fn ($query) => $query->excludingDrafts())
            ->with(['author:id,uuid,name', 'registerTypeCollege'])
            ->withCount('mediaFiles')
            ->selectRaw('(select coalesce(sum(mf.size_bytes), 0) from media_files mf where mf.oeuvre_id = oeuvres.id and mf.deleted_at is null) as files_size_bytes')
            ->selectRaw(
                "(select case when count(*) = 0 then 0 when sum(case when mf.status = 'ready' then 1 else 0 end) = count(*) then 1 else 0 end ".
                'from media_files mf where mf.oeuvre_id = oeuvres.id and mf.deleted_at is null) as all_ready'
            )
            ->selectRaw(
                '(select case when count(*) > 0 then 1 else 0 end from media_files mf '.
                "where mf.oeuvre_id = oeuvres.id and mf.deleted_at is null and mf.status in ('failed', 'quarantined')) as has_blocking_file"
            )
            ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->when($status !== '' && in_array($status, self::FILTERABLE_STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($author !== '', fn ($query) => $query->whereHas(
                'author',
                fn ($q) => $q->where('uuid', $author)->orWhere('name', 'like', "%{$author}%")->orWhere('email', 'like', "%{$author}%")
            ))
            ->when($from !== '', fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn ($query) => $query->whereDate('created_at', '<=', $to))
            // The queue is ordered by when a deposit last entered it,
            // not by when the author created the record — a draft filed
            // in January and submitted today belongs at the top. A draft
            // has no `submitted_at`; the explicit null test keeps those
            // rows last on every driver instead of relying on NULL order.
            ->orderByRaw('oeuvres.submitted_at is null')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, self::PER_PAGE))
            ->withQueryString();

        return Inertia::render('admin/oeuvres/Index', [
            'oeuvres' => $oeuvres->through(fn (Oeuvre $oeuvre) => [
                'uuid' => $oeuvre->uuid,
                'title' => $oeuvre->title,
                'college_name' => AuthorOeuvreController::collegeName($oeuvre),
                'status' => $oeuvre->status,
                'author' => $oeuvre->author?->only(['uuid', 'name']),
                'submitted_at' => $oeuvre->submitted_at,
                'files_count' => (int) $oeuvre['media_files_count'],
                'files_size_bytes' => (int) $oeuvre['files_size_bytes'],
                'all_ready' => (bool) $oeuvre['all_ready'],
                'has_blocking_file' => (bool) $oeuvre['has_blocking_file'],
                'created_at' => $oeuvre->created_at,
            ]),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'author' => $author,
                'from' => $from,
                'to' => $to,
            ],
            'statuses' => self::FILTERABLE_STATUSES,
        ]);
    }

    public function show(Request $request, Oeuvre $oeuvre, SubmissionGate $gate): Response
    {
        /** @var User $admin */
        $admin = $request->user();

        Gate::forUser($admin)->authorize('inspect', $oeuvre);

        $oeuvre->load(['author:id,uuid,name,first_name,last_name', 'registerTypeCollege', 'reviewer:id,uuid,name']);

        $files = $oeuvre->mediaFiles()
            ->orderBy('created_at')
            ->get()
            ->map(function (MediaFile $mediaFile) {
                return [
                    'uuid' => $mediaFile->uuid,
                    'original_name' => $mediaFile->original_name,
                    'extension' => $mediaFile->extension,
                    'mime' => $mediaFile->mime,
                    'size_bytes' => $mediaFile->size_bytes,
                    'status' => $mediaFile->status,
                    'duration_sec' => $mediaFile->duration_sec,
                    'width' => $mediaFile->width,
                    'height' => $mediaFile->height,
                    'sha256_plain' => $mediaFile->sha256_plain,
                    'scan_result' => $this->scanResult($mediaFile),
                    'scanned_at' => $mediaFile->scanned_at,
                    'verified_at' => $mediaFile->verified_at,
                    'created_at' => $mediaFile->created_at,
                    'variants' => $mediaFile->variants()->get(['uuid', 'kind'])->pluck('kind'),
                ];
            });

        // Reading someone else's unsubmitted work is a privileged act: one
        // ledger row per file, naming the officer. Other statuses are
        // unchanged — their reads are logged where bytes are actually served.
        if ($oeuvre->status === OeuvreStatus::DRAFT) {
            $oeuvre->mediaFiles()->get()->each(fn (MediaFile $file) => AccessLogger::record(
                $file,
                $admin->id,
                'inspect',
                $request->ip() ?? 'unknown',
                $request->userAgent(),
            ));
        }

        return Inertia::render('admin/oeuvres/Show', [
            'oeuvre' => [
                'uuid' => $oeuvre->uuid,
                'title' => $oeuvre->title,
                'college_name' => AuthorOeuvreController::collegeName($oeuvre),
                'description' => $oeuvre->description,
                'status' => $oeuvre->status,
                'author' => $oeuvre->author?->only(['uuid', 'name']),
                'created_at' => $oeuvre->created_at,
                'submitted_at' => $oeuvre->submitted_at,
                'reviewed_at' => $oeuvre->reviewed_at,
                'registered_at' => $oeuvre->registered_at,
                'is_draft' => $oeuvre->status === OeuvreStatus::DRAFT,
            ],
            'files' => $files,
            // Who holds this deposit right now, and whether that is the
            // officer reading the page. The page offers an explicit
            // take-over rather than silently letting a second officer
            // decide — see OeuvreStatusMachine's concurrency note.
            'holder' => $oeuvre->reviewer === null ? null : [
                'uuid' => $oeuvre->reviewer->uuid,
                'name' => $oeuvre->reviewer->name,
                'is_you' => $oeuvre->reviewed_by === $request->user()->id,
            ],
            // The officer's call: these are the required documents whose
            // `conditions` were never evaluated, so the author was told
            // they might not apply and was allowed to submit without them.
            // See SubmissionGate.
            'advisories' => $gate->evaluate($oeuvre)->toArray()['advisories'],
            // Why it was rejected last time. An officer looking at a
            // resubmission needs that before reading a single file.
            'reviews' => AuthorOeuvreController::reviewHistory($oeuvre),
        ]);
    }

    /**
     * Derived, not stored — no pipeline job currently stamps `scanned_at`
     * for a clean result (see the admin-console task's Part 0 findings), so
     * "clean" is inferred from having passed the `scanning` stage of the
     * status machine rather than from that column being non-null. This is
     * the same "degrade honestly" principle the task applies to variants,
     * applied here to integrity reporting instead of inventing a timestamp
     * the pipeline never recorded.
     */
    private function scanResult(MediaFile $mediaFile): string
    {
        if ($mediaFile->status === 'quarantined') {
            return 'infected';
        }

        if (in_array($mediaFile->status, ['uploading', 'assembling', 'scanning'], true)) {
            return 'pending';
        }

        if ($mediaFile->status === 'failed') {
            return 'unknown';
        }

        return 'clean';
    }
}
