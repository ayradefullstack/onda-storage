<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Quota\QuotaPolicy;
use App\Http\Controllers\Controller;
use App\Models\StorageQuota;
use App\Models\User;
use App\Models\Wilaya;
use App\Models\Work;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only browsing surface for the review console — an officer looking
 * for "who has registered and what have they deposited" (see
 * CLAUDE.md/admin-console task). No mutation lives here; the approve/reject
 * workflow is a separate, later task.
 *
 * Every aggregate (works/files count, last activity) is a correlated
 * subquery folded into the single index SELECT via `selectRaw`/`addSelect`,
 * not `withCount`/`withSum` on a relation — `User` has no `works()` relation
 * to hang those off (a work belongs to an author by `author_id`, and
 * `media_files` only reaches an author through its `work`), and adding one
 * would be a `Model` change outside this task's owned files. Storage used
 * is read straight from `storage_quotas.used_bytes` (already the running
 * total `CompleteUpload` maintains) rather than re-summing `media_files`.
 */
final class AuthorController extends Controller
{
    private const PER_PAGE = 25;

    /**
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'activity' => 'last_activity_at',
        'quota' => 'quota_pct',
        'works' => 'works_count',
        'files' => 'files_count',
        'name' => 'users.name',
    ];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        $wilayaUuid = trim((string) $request->string('wilaya'));
        $sort = (string) $request->string('sort', 'activity');
        $direction = (string) $request->string('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $sortColumn = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['activity'];
        $defaultLimitBytes = QuotaPolicy::DEFAULT_LIMIT_BYTES;

        $authors = User::query()
            ->role('author')
            ->select('users.uuid', 'users.name', 'users.email', 'users.wilaya_id')
            ->selectRaw(
                '(select count(*) from works w where w.author_id = users.id and w.deleted_at is null) as works_count'
            )
            ->selectRaw(
                '(select count(*) from media_files mf inner join works w on w.id = mf.work_id '.
                'where w.author_id = users.id and mf.deleted_at is null and w.deleted_at is null) as files_count'
            )
            ->selectRaw(
                '(select max(t.d) from ('.
                'select max(w.created_at) as d from works w where w.author_id = users.id and w.deleted_at is null '.
                'union all '.
                'select max(mf.created_at) as d from media_files mf inner join works w2 on w2.id = mf.work_id '.
                'where w2.author_id = users.id and mf.deleted_at is null and w2.deleted_at is null'.
                ') t) as last_activity_at'
            )
            ->leftJoin('storage_quotas', 'storage_quotas.user_id', '=', 'users.id')
            ->addSelect('storage_quotas.used_bytes as quota_used_bytes', 'storage_quotas.limit_bytes as quota_limit_bytes')
            ->selectRaw(
                "(coalesce(storage_quotas.used_bytes, 0) / coalesce(nullif(storage_quotas.limit_bytes, 0), {$defaultLimitBytes})) as quota_pct"
            )
            ->with('wilaya:id,name_fr,name_ar')
            ->when($search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('users.name', 'like', "%{$search}%")->orWhere('users.email', 'like', "%{$search}%")
            ))
            ->when($wilayaUuid !== '', fn ($query) => $query->whereHas('wilaya', fn ($q) => $q->where('uuid', $wilayaUuid)))
            ->orderByRaw("({$sortColumn} is null) asc, {$sortColumn} {$direction}")
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/authors/Index', [
            'authors' => $authors->through(fn (User $author) => [
                'uuid' => $author->uuid,
                'name' => $author->name,
                'email' => $author->email,
                'wilaya' => $author->wilaya?->only(['name_fr', 'name_ar']),
                'works_count' => (int) $author['works_count'],
                'files_count' => (int) $author['files_count'],
                'quota_used_bytes' => (int) ($author['quota_used_bytes'] ?? 0),
                'quota_limit_bytes' => (int) ($author['quota_limit_bytes'] ?? $defaultLimitBytes),
                'last_activity_at' => $author['last_activity_at'],
            ]),
            'filters' => [
                'search' => $search,
                'wilaya' => $wilayaUuid,
                'sort' => $sort,
                'direction' => $direction,
            ],
            'wilayas' => Wilaya::query()->active()->visible()->orderBy('name_fr')->get(['uuid', 'name_fr', 'name_ar']),
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        abort_if($user->hasRole('admin'), 404);

        $status = (string) $request->string('status');

        // A draft is an author's private working state, not a submission
        // (see Admin\WorkController's docblock) — excluded here too, not
        // only from the cross-author works index, so every row on this
        // page links somewhere that actually renders rather than 404ing.
        $works = Work::query()
            ->where('author_id', $user->id)
            ->where('status', '!=', 'draft')
            ->withCount('mediaFiles')
            ->selectRaw('(select coalesce(sum(mf.size_bytes), 0) from media_files mf where mf.work_id = works.id and mf.deleted_at is null) as files_size_bytes')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $statusTally = Work::query()
            ->where('author_id', $user->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $lastDeposit = Work::query()
            ->join('media_files', 'media_files.work_id', '=', 'works.id')
            ->where('works.author_id', $user->id)
            ->whereNull('media_files.deleted_at')
            ->max('media_files.created_at');

        $quota = StorageQuota::where('user_id', $user->id)->first();

        return Inertia::render('admin/authors/Show', [
            'author' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'first_name_ar' => $user->first_name_ar,
                'last_name_ar' => $user->last_name_ar,
                'email' => $user->email,
                'phone' => $user->phone,
                'wilaya' => $user->wilaya?->only(['name_fr', 'name_ar']),
                'commune' => $user->commune?->only(['name_fr', 'name_ar']),
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at,
            ],
            'quota' => [
                'used_bytes' => $quota->used_bytes ?? 0,
                'limit_bytes' => $quota->limit_bytes ?? QuotaPolicy::DEFAULT_LIMIT_BYTES,
            ],
            'works' => $works->through(fn (Work $work) => [
                'uuid' => $work->uuid,
                'title' => $work->title,
                'status' => $work->status,
                'files_count' => (int) $work['media_files_count'],
                'files_size_bytes' => (int) $work['files_size_bytes'],
                'created_at' => $work->created_at,
            ]),
            'filters' => ['status' => $status],
            'activity' => [
                'last_deposit_at' => $lastDeposit,
                'status_tally' => $statusTally,
            ],
        ]);
    }
}
