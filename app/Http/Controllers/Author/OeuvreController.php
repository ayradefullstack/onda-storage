<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Oeuvre\DeleteOeuvre;
use App\Domain\Deposit\Exceptions\RemovalRefused;
use App\Domain\Deposit\OeuvreStatus;
use App\Domain\Deposit\RemovalGate;
use App\Domain\Deposit\SlotProgressQuery;
use App\Domain\Deposit\SubmissionGate;
use App\Domain\Quota\QuotaPolicy;
use App\Http\Controllers\Concerns\ResolvesPerPage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Author\StoreOeuvreRequest;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\StorageQuota;
use App\Models\TypeGestion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin page-route dispatch for the works UI (P4). No upload logic lives
 * here — that's the P3 UploadController; this only lists/creates/shows
 * `Oeuvre` records and hands the P3 session data to the page so the upload
 * store can act on it.
 */
final class OeuvreController extends Controller
{
    use ResolvesPerPage;

    private const PER_PAGE = 15;

    /**
     * Every status an author's own shelf can show, in queue order. Unlike
     * the admin console, `draft` belongs here: it is the author's own
     * working state.
     *
     * @var list<string>
     */
    private const STATUSES = [
        OeuvreStatus::DRAFT,
        OeuvreStatus::SUBMITTED,
        OeuvreStatus::UNDER_REVIEW,
        OeuvreStatus::REGISTERED,
        OeuvreStatus::REJECTED,
    ];

    /** @var list<string> */
    private const SORTS = ['newest', 'oldest', 'title', 'files'];

    /**
     * The works table. Search, status filter, sort and pagination all run
     * on the server: the page renders one page of rows, so a client-side
     * filter would only ever be filtering the page it can already see.
     *
     * Query budget — this method issues a FIXED number of queries whatever
     * the page size (OeuvresTableTest asserts it):
     *   1. the paginator's count(*)
     *   2. the page itself — `withCount('mediaFiles')` and the aggregate
     *      subquery below fold into that same statement
     *   3. eager-load registerTypeCollege
     *   4+5. SlotProgressQuery, for the whole page at once
     *   6. the status counts behind the filter tabs
     * Nothing is computed per row. `Oeuvre::requiredDocumentsProgress()` is
     * deliberately NOT called here: it costs two queries per oeuvre, which
     * on a 15-row table is thirty.
     */
    public function index(Request $request, SlotProgressQuery $slotProgress): Response
    {
        $user = $request->user();
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status');
        $sort = (string) $request->string('sort');

        $oeuvres = Oeuvre::query()
            ->where('author_id', $user->id)
            ->with('registerTypeCollege')
            ->withCount('mediaFiles')
            // Any file not at `ready` blocks submission — either its
            // pipeline is still running or it failed. A subquery inside the
            // page statement, not a question asked once per row.
            ->selectRaw(
                '(select case when count(*) > 0 then 1 else 0 end from media_files mf '.
                "where mf.oeuvre_id = oeuvres.id and mf.deleted_at is null and mf.status != 'ready') as has_unready_file"
            )
            ->when($search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('title', 'like', "%{$search}%")
                    ->orWhere('uuid', 'like', "%{$search}%")
                    ->orWhereHas('registerTypeCollege', fn ($college) => $college
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('name_ar', 'like', "%{$search}%")
                        ->orWhere('name_en', 'like', "%{$search}%"))
            ))
            ->when(in_array($status, self::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->tap(fn (Builder $query) => $this->applySort($query, $sort))
            ->paginate($this->perPage($request, self::PER_PAGE))
            ->withQueryString();

        $progress = $slotProgress->forPage($oeuvres->getCollection());

        return Inertia::render('author/oeuvres/Index', [
            'oeuvres' => $oeuvres->through(fn (Oeuvre $oeuvre): array => [
                'id' => $oeuvre->id,
                'uuid' => $oeuvre->uuid,
                'title' => $oeuvre->title,
                'description' => $oeuvre->description,
                'status' => $oeuvre->status,
                'created_at' => $oeuvre->created_at,
                'submitted_at' => $oeuvre->submitted_at,
                'media_files_count' => (int) $oeuvre['media_files_count'],
                'college_name' => self::collegeName($oeuvre),
                'documents' => $progress[$oeuvre->id],
                // What the row may offer. `edit` and `delete` mirror the
                // policy exactly, and the policy is what actually enforces
                // them — a hidden button is not a permission.
                //
                // `submit` is ADVISORY: the cheap, page-wide approximation
                // of SubmissionGate, so the button can be greyed without
                // running the real gate once per row. The real gate runs on
                // POST and is what decides; this only decides whether to
                // offer the button.
                'can' => [
                    'edit' => $user->can('update', $oeuvre),
                    'delete' => $user->can('delete', $oeuvre),
                    'submit' => $user->can('submit', $oeuvre)
                        && (int) $oeuvre['media_files_count'] > 0
                        && ! (bool) $oeuvre['has_unready_file']
                        && $progress[$oeuvre->id]['satisfied'] + $progress[$oeuvre->id]['conditional'] >= $progress[$oeuvre->id]['total'],
                ],
            ]),
            'filters' => [
                'search' => $search,
                'status' => in_array($status, self::STATUSES, true) ? $status : '',
                'sort' => in_array($sort, self::SORTS, true) ? $sort : self::SORTS[0],
            ],
            'statuses' => self::STATUSES,
            'sorts' => self::SORTS,
            // One grouped query for the filter tabs. Counted over the
            // author's whole shelf, not the current page — a tab reading
            // "(3)" must not change when you turn the page.
            'counts' => self::statusCounts($user->id),
        ]);
    }

    /**
     * The whole deposit, with its files. Soft delete; the vault bytes and
     * the author's quota are untouched — see DeleteOeuvre.
     */
    public function destroy(Request $request, Oeuvre $oeuvre, DeleteOeuvre $delete): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('delete', $oeuvre);

        try {
            $delete->handle($oeuvre);
        } catch (RemovalRefused $e) {
            // 422, not 403: the author is allowed to delete this deposit —
            // just not while one of its files is mid-pipeline.
            throw ValidationException::withMessages(['oeuvre' => [$e->getMessage()]]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The work has been deleted.')]);

        return to_route('oeuvres.index');
    }

    /**
     * @param  Builder<Oeuvre>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match (in_array($sort, self::SORTS, true) ? $sort : self::SORTS[0]) {
            // `title` sorts by the stored title with untitled rows last:
            // the label the table displays is built client-side from the
            // collège and the creation date, so the database cannot order
            // by it. The secondary key keeps untitled rows in a stable
            // order rather than an arbitrary one.
            'title' => $query->orderByRaw('(title is null or title = ?) asc', [''])->orderBy('title')->orderByDesc('id'),
            'files' => $query->orderByDesc('media_files_count')->orderByDesc('id'),
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * @return array<string, int>
     */
    private static function statusCounts(int $authorId): array
    {
        $counts = Oeuvre::query()
            ->where('author_id', $authorId)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        $byStatus = [];

        foreach (self::STATUSES as $status) {
            $byStatus[$status] = (int) ($counts[$status] ?? 0);
        }

        $byStatus['all'] = array_sum($byStatus);

        return $byStatus;
    }

    public function create(): Response
    {
        return Inertia::render('author/oeuvres/Create', [
            'classification' => $this->classificationTree(),
        ]);
    }

    public function store(StoreOeuvreRequest $request): RedirectResponse
    {
        $oeuvre = new Oeuvre;
        $oeuvre->author_id = $request->user()->id;
        $oeuvre->fill($request->classification());
        $oeuvre->status = OeuvreStatus::DRAFT;
        $oeuvre->save();

        return to_route('oeuvres.show', $oeuvre);
    }

    /**
     * Also the status-polling target: the frontend re-requests this same
     * page via Inertia's partial-reload mechanism (only: ['mediaFiles']) on
     * a backing-off interval rather than a bespoke JSON endpoint — it's the
     * same data, and Inertia already knows how to fetch just that prop.
     */
    public function show(Request $request, Oeuvre $oeuvre, SubmissionGate $gate, RemovalGate $removalGate): Response
    {
        Gate::forUser($request->user())->authorize('view', $oeuvre);

        // The one question that decides whether step 2 renders management
        // controls at all. Asked once, from the policy, and passed to the
        // page — a frozen deposit shows its files read-only.
        $editable = $request->user()->can('update', $oeuvre);

        // withTrashed: a reference row an admin retired after filing is still
        // part of this deposit's record, so the card keeps showing it.
        $oeuvre->load([
            'registerType' => fn ($query) => $query->withTrashed(),
            'typeGestion' => fn ($query) => $query->withTrashed(),
            'registerTypeCollege' => fn ($query) => $query->withTrashed(),
            'registerTypeMember' => fn ($query) => $query->withTrashed(),
        ]);

        $quota = StorageQuota::where('user_id', $request->user()->id)->first();

        return Inertia::render('author/oeuvres/Show', [
            // 'id' (the numeric FK) is included deliberately — the P3
            // InitUpload endpoint takes oeuvre_id as an integer, and this is
            // a page prop, not a URL, so it doesn't touch the
            // never-expose-sequential-ids-in-URLs rule.
            'oeuvre' => [
                ...$oeuvre->only(['id', 'uuid', 'title', 'description', 'status', 'created_at', 'code_college_snapshot', 'submitted_at', 'reviewed_at', 'registered_at']),
                'college_name' => self::collegeName($oeuvre),
                // Adding files, removing them, submitting, deleting: all
                // four are the policy's answer, not the template's guess.
                'can' => [
                    'edit' => $editable,
                    'delete' => $request->user()->can('delete', $oeuvre),
                ],
            ],
            'classification' => self::classificationSummary($oeuvre),
            // Step 2: one upload slot per required document of the collège,
            // in display order. Empty for an unclassified oeuvre, which keeps
            // the single dropzone.
            'requirements' => self::requirementSlots($oeuvre),
            // Reloaded with mediaFiles on every poll: it only moves when a
            // file reaches `ready`.
            'progress' => fn (): array => $oeuvre->requiredDocumentsProgress(),
            // The submission gate's whole verdict, not just a boolean: the
            // page lists every blocker at once, and shows the empty
            // *conditional* slots as advisory rather than as blockers (see
            // SubmissionGate's docblock for why those do not block).
            // Recomputed on every poll alongside mediaFiles, since a file
            // reaching `ready` is exactly what unblocks it.
            'submission' => fn (): array => [
                ...$gate->evaluate($oeuvre)->toArray(),
                // The gate answers "is it ready"; the policy answers "may
                // this author still act on it at all". A submitted oeuvre
                // is frozen, so neither the button nor the blockers apply.
                'is_open' => $oeuvre->isAuthorEditable(),
            ],
            // The decision history, so a rejected author sees the officer's
            // reason on the page and not only in the bell.
            'reviews' => self::reviewHistory($oeuvre),
            // Read-only: lets the upload UI show remaining quota before a
            // file consumes it. No row yet means the same "unlimited until
            // the default allocation" convention QuotaPolicy already uses.
            'quota' => [
                'used_bytes' => $quota->used_bytes ?? 0,
                'limit_bytes' => $quota->limit_bytes ?? QuotaPolicy::DEFAULT_LIMIT_BYTES,
            ],
            'mediaFiles' => $oeuvre->mediaFiles()
                ->orderBy('created_at')
                ->get()
                ->map(fn (MediaFile $mediaFile) => [
                    'uuid' => $mediaFile->uuid,
                    // Whether the remove control applies to this file. Two
                    // separate questions, deliberately kept apart: the
                    // deposit must still be the author's to edit, and this
                    // file's pipeline must have finished with it. A file
                    // that is merely mid-pipeline shows the control
                    // disabled with a reason, rather than not at all.
                    'can_remove' => $editable && $removalGate->isFileRemovable($mediaFile),
                    'college_oeuvre_file_id' => $mediaFile->college_oeuvre_file_id,
                    'original_name' => $mediaFile->original_name,
                    'extension' => $mediaFile->extension,
                    'mime' => $mediaFile->mime,
                    'size_bytes' => $mediaFile->size_bytes,
                    'status' => $mediaFile->status,
                    'duration_sec' => $mediaFile->duration_sec,
                    'width' => $mediaFile->width,
                    'height' => $mediaFile->height,
                    'sha256_plain' => $mediaFile->sha256_plain,
                    'variant_count' => $mediaFile->variants()->count(),
                    'created_at' => $mediaFile->created_at,
                ]),
        ]);
    }

    /**
     * The append-only decision history, oldest first. The officer's name is
     * included for the admin console; the author's page shows only the
     * decision and its reason.
     *
     * `withTrashed` on the actor: `users` is soft-deleted, and a decision
     * must still name its officer after that account is retired.
     *
     * @return array<int, array{uuid: string, from_status: string, to_status: string, reason: string|null, actor: string|null, created_at: string|null}>
     */
    public static function reviewHistory(Oeuvre $oeuvre): array
    {
        return $oeuvre->oeuvreReviews()
            ->with(['actor' => fn ($query) => $query->withTrashed()])
            ->get()
            ->map(fn (OeuvreReview $review): array => [
                'uuid' => $review->uuid,
                'from_status' => $review->from_status,
                'to_status' => $review->to_status,
                'reason' => $review->reason,
                'actor' => $review->actor?->name,
                'created_at' => $review->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * The college's name in the request locale (falling back to French), for
     * the display label of an oeuvre that has no title yet.
     */
    public static function collegeName(Oeuvre $oeuvre): ?string
    {
        $college = $oeuvre->registerTypeCollege;

        return $college === null ? null : trim($college->name_global);
    }

    /**
     * The collège's required documents as upload slots. Titles in the request
     * locale, falling back to French.
     *
     * `conditions` is passed raw, for display only: the declaration fields it
     * tests do not exist yet, so nothing evaluates it — the page marks such a
     * slot as possibly not applying rather than hiding it.
     *
     * @return array<int, array{id: int, document_key: string, title: string, extensions: list<string>, is_required: bool, max_size_kb: int|null, allows_multiple: bool, conditions: array<string, mixed>|null}>
     */
    private static function requirementSlots(Oeuvre $oeuvre): array
    {
        if ($oeuvre->register_type_college_id === null) {
            return [];
        }

        return $oeuvre->requirements()->get()
            ->map(fn (CollegeOeuvreFile $requirement): array => [
                'id' => $requirement->id,
                'document_key' => $requirement->document_key,
                'title' => trim($requirement->title_global),
                'extensions' => $requirement->extensions,
                'is_required' => $requirement->is_required,
                'max_size_kb' => $requirement->max_size_kb,
                'allows_multiple' => $requirement->allows_multiple,
                'conditions' => $requirement->conditions,
            ])
            ->values()
            ->all();
    }

    /**
     * The branch the oeuvre was filed under, for the card on the upload page —
     * names in the request locale (falling back to French), trimmed. Null for
     * an oeuvre created before classification existed.
     *
     * The collège code is the snapshot, not the college's current code: the
     * card shows what the deposit was filed under.
     *
     * @return array{type: string, gestion: string|null, college: string, code_college: string|null, member: string, code_qlt: string|null}|null
     */
    private static function classificationSummary(Oeuvre $oeuvre): ?array
    {
        $type = $oeuvre->registerType;
        $college = $oeuvre->registerTypeCollege;
        $member = $oeuvre->registerTypeMember;

        if ($type === null || $college === null || $member === null) {
            return null;
        }

        return [
            'type' => trim($type->name_global),
            'gestion' => $oeuvre->typeGestion === null ? null : trim($oeuvre->typeGestion->name_global),
            'college' => trim($college->name_global),
            'code_college' => $oeuvre->code_college_snapshot,
            'member' => trim($member->name_global),
            'code_qlt' => $member->code_qlt,
        ];
    }

    /**
     * The whole selectable tree in one eager-loaded query — 4 types, 3
     * gestions, 21 colleges, 94 members — so the cascade runs client-side
     * with no round trip per select.
     *
     * Only the fields the page renders are sent; models are never passed
     * whole, since Inertia serialises every attribute into the page source.
     *
     * Filtering happens here, not in the seeder:
     * - status = 1 only, at every level;
     * - REFERENTIEL_HORS_ADHESION never appears (it is a reference list, not
     *   a depositable category). An uncoded college is excluded by the same
     *   comparison, which is intended: nothing can be filed under no code;
     * - disabled colleges (OEUVRE_FILM) are kept, marked, for the form to
     *   render unselectable;
     * - members limited to available_in_registration.
     *
     * Names are resolved server-side by `name_global` (request locale,
     * falling back to `name`) and trimmed — ONDA's names carry leading
     * spaces. The page reloads just this prop when the viewer switches
     * language.
     *
     * @return array{types: array<int, array<string, mixed>>}
     */
    private function classificationTree(): array
    {
        $types = RegisterType::query()
            ->active()
            ->with([
                'typeGestions' => fn ($query) => $query->active()->orderBy('type_gestion'),
                'registerTypeColleges' => fn ($query) => $query
                    ->where('status', RegisterTypeCollege::STATUS_ACTIVE)
                    ->where('code_college', '!=', RegisterTypeCollege::CODE_REFERENTIEL_HORS_ADHESION)
                    ->orderBy('id'),
                'registerTypeColleges.registerTypeMembers' => fn ($query) => $query
                    ->where('status', RegisterTypeCollege::STATUS_ACTIVE)
                    ->where('available_in_registration', true)
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        return [
            'types' => $types->map(fn (RegisterType $type): array => [
                'id' => $type->id,
                'name' => trim($type->name_global),
                // Despite the name (kept so the cascade component and its
                // tests are untouched), this means "this type has a gestion
                // level": it has at least one active gestion — the same
                // predicate StoreOeuvreRequest validates with.
                'is_auteur' => $type->typeGestions->isNotEmpty(),
                'is_disabled' => $type->is_disabled,
                'gestions' => $type->typeGestions->map(fn (TypeGestion $gestion): array => [
                    'id' => $gestion->id,
                    'name' => trim($gestion->name_global),
                ])->values()->all(),
                // A type with a gestion level offers only colleges under an
                // active gestion; one without offers only gestion-less
                // colleges. Anything else would be a branch the form cannot
                // complete.
                'colleges' => $type->registerTypeColleges
                    ->filter(fn (RegisterTypeCollege $college): bool => $type->typeGestions->isNotEmpty()
                        ? $type->typeGestions->contains('id', $college->type_gestion_id)
                        : $college->type_gestion_id === null)
                    ->map(fn (RegisterTypeCollege $college): array => [
                        'id' => $college->id,
                        'name' => trim($college->name_global),
                        'code_college' => $college->code_college,
                        'type_gestion_id' => $college->type_gestion_id,
                        'is_disabled' => $college->is_disabled,
                        'members' => $college->registerTypeMembers->map(fn (RegisterTypeMember $member): array => [
                            'id' => $member->id,
                            'name' => trim($member->name_global),
                            'available_in_registration' => $member->available_in_registration,
                        ])->values()->all(),
                    ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
