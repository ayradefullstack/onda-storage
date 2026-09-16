<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Domain\Quota\QuotaPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Author\StoreOeuvreRequest;
use App\Models\Oeuvre;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\StorageQuota;
use App\Models\TypeGestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
    public function index(Request $request): Response
    {
        $oeuvres = Oeuvre::query()
            ->where('author_id', $request->user()->id)
            ->with('registerTypeCollege')
            ->withCount('mediaFiles')
            ->latest()
            ->get()
            ->map(fn (Oeuvre $oeuvre): array => [
                'id' => $oeuvre->id,
                'uuid' => $oeuvre->uuid,
                'title' => $oeuvre->title,
                'description' => $oeuvre->description,
                'status' => $oeuvre->status,
                'created_at' => $oeuvre->created_at,
                'media_files_count' => (int) $oeuvre['media_files_count'],
                'college_name' => self::collegeName($oeuvre),
            ]);

        return Inertia::render('author/oeuvres/Index', [
            'oeuvres' => $oeuvres,
        ]);
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
        $oeuvre->status = 'draft';
        $oeuvre->save();

        return to_route('oeuvres.show', $oeuvre);
    }

    /**
     * Also the status-polling target: the frontend re-requests this same
     * page via Inertia's partial-reload mechanism (only: ['mediaFiles']) on
     * a backing-off interval rather than a bespoke JSON endpoint — it's the
     * same data, and Inertia already knows how to fetch just that prop.
     */
    public function show(Request $request, Oeuvre $oeuvre): Response
    {
        Gate::forUser($request->user())->authorize('view', $oeuvre);

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
                ...$oeuvre->only(['id', 'uuid', 'title', 'description', 'status', 'created_at', 'code_college_snapshot']),
                'college_name' => self::collegeName($oeuvre),
            ],
            'classification' => self::classificationSummary($oeuvre),
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
                ->map(fn ($mediaFile) => [
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
                    'variant_count' => $mediaFile->variants()->count(),
                    'created_at' => $mediaFile->created_at,
                ]),
        ]);
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
                'typeGestions' => fn ($query) => $query->orderBy('type_gestion'),
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
                'is_auteur' => $type->slug === StoreOeuvreRequest::AUTEUR_SLUG,
                'is_disabled' => $type->is_disabled,
                'gestions' => $type->typeGestions->map(fn (TypeGestion $gestion): array => [
                    'id' => $gestion->id,
                    'name' => trim($gestion->name_global),
                ])->values()->all(),
                'colleges' => $type->registerTypeColleges->map(fn (RegisterTypeCollege $college): array => [
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
