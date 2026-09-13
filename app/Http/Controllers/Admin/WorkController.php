<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\Work;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The other half of the review console: every submitted work across every
 * author (see AuthorController's docblock for the aggregate-query
 * rationale, which this mirrors).
 *
 * A `draft` is an author's private working state, never a submission — it
 * is excluded by `whereIn` on the base query below, not filtered out in the
 * Vue template, so it can never leak onto the page even transiently.
 */
final class WorkController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * Every status an admin may ever be shown. `draft` is deliberately
     * absent — see class docblock.
     *
     * @var list<string>
     */
    private const REVIEWABLE_STATUSES = ['submitted', 'under_review', 'registered', 'rejected'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status');
        $author = trim((string) $request->string('author'));
        $from = trim((string) $request->string('from'));
        $to = trim((string) $request->string('to'));

        $works = Work::query()
            ->whereIn('status', self::REVIEWABLE_STATUSES)
            ->with('author:id,uuid,name')
            ->withCount('mediaFiles')
            ->selectRaw('(select coalesce(sum(mf.size_bytes), 0) from media_files mf where mf.work_id = works.id and mf.deleted_at is null) as files_size_bytes')
            ->selectRaw(
                "(select case when count(*) = 0 then 0 when sum(case when mf.status = 'ready' then 1 else 0 end) = count(*) then 1 else 0 end ".
                'from media_files mf where mf.work_id = works.id and mf.deleted_at is null) as all_ready'
            )
            ->selectRaw(
                '(select case when count(*) > 0 then 1 else 0 end from media_files mf '.
                "where mf.work_id = works.id and mf.deleted_at is null and mf.status in ('failed', 'quarantined')) as has_blocking_file"
            )
            ->when($search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->when($status !== '' && in_array($status, self::REVIEWABLE_STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($author !== '', fn ($query) => $query->whereHas(
                'author',
                fn ($q) => $q->where('uuid', $author)->orWhere('name', 'like', "%{$author}%")->orWhere('email', 'like', "%{$author}%")
            ))
            ->when($from !== '', fn ($query) => $query->whereDate('created_at', '>=', $from))
            ->when($to !== '', fn ($query) => $query->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/works/Index', [
            'works' => $works->through(fn (Work $work) => [
                'uuid' => $work->uuid,
                'title' => $work->title,
                'status' => $work->status,
                'author' => $work->author?->only(['uuid', 'name']),
                'files_count' => (int) $work['media_files_count'],
                'files_size_bytes' => (int) $work['files_size_bytes'],
                'all_ready' => (bool) $work['all_ready'],
                'has_blocking_file' => (bool) $work['has_blocking_file'],
                'created_at' => $work->created_at,
            ]),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'author' => $author,
                'from' => $from,
                'to' => $to,
            ],
            'statuses' => self::REVIEWABLE_STATUSES,
        ]);
    }

    public function show(Work $work): Response
    {
        abort_if($work->status === 'draft', 404);

        $work->load('author:id,uuid,name,first_name,last_name');

        $files = $work->mediaFiles()
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

        return Inertia::render('admin/works/Show', [
            'work' => [
                'uuid' => $work->uuid,
                'title' => $work->title,
                'description' => $work->description,
                'status' => $work->status,
                'author' => $work->author?->only(['uuid', 'name']),
                'created_at' => $work->created_at,
                'registered_at' => $work->registered_at,
            ],
            'files' => $files,
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
