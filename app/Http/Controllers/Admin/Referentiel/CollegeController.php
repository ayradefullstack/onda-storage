<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Referentiel;

use App\Actions\Referentiel\CreateCollege;
use App\Domain\Deposit\OeuvreStatus;
use App\Http\Requests\Admin\Referentiel\StoreCollegeRequest;
use App\Http\Requests\Admin\Referentiel\UpdateCollegeRequest;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\TypeGestion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Colleges — the tab with the widest blast radius, because a college is
 * what oeuvres are filed under.
 */
final class CollegeController extends ReferentielController
{
    /**
     * The columns this form may write. Everything else on the row —
     * `code_college`, `register_type_id`, `type_gestion*`, `code_dv` — is
     * frozen after creation and rendered read-only with its reason. `name`
     * is in the list but UpdateCollegeRequest refuses it on a system row
     * (the seeder matches system colleges on it).
     *
     * @var list<string>
     */
    public const EDITABLE = ['name', 'name_ar', 'name_en', 'status', 'is_disabled', 'adhesion'];

    private const OEUVRE_STATUSES = [
        OeuvreStatus::DRAFT,
        OeuvreStatus::SUBMITTED,
        OeuvreStatus::UNDER_REVIEW,
        OeuvreStatus::REGISTERED,
        OeuvreStatus::REJECTED,
    ];

    /**
     * Query budget — 11, flat, whatever the page size (pinned in
     * ReferentielAccessTest): the paginator count, the page itself with all
     * its aggregates folded in (members, documents, oeuvres, in-flight, and
     * one count per oeuvre status), the two eager-loaded parents, the five
     * tab counts, and the two filter/create dropdown lookups.
     * Every count is a `withCount`, never a relation load: 22 colleges each
     * lazy-loading members would be 22 extra queries.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        // Filters travel as uuids — no sequential id in a URL.
        $type = $this->idForUuid(RegisterType::class, $request->query('type')) ?? 0;
        $gestion = $this->idForUuid(TypeGestion::class, $request->query('gestion')) ?? 0;

        $colleges = RegisterTypeCollege::query()
            ->with(['registerType:id,uuid,name,name_ar,name_en', 'typeGestion:id,uuid,name,name_ar,name_en'])
            ->withCount(['registerTypeMembers', 'collegeOeuvreFiles', 'oeuvres'])
            // The blast radius of retiring a college is its oeuvres BY STATUS.
            // One `withCount` per status folds into the same statement as the
            // page itself — a grouped query would be a ninth round trip.
            ->withCount(self::statusCounts())
            // In-flight deposits, as its own subquery rather than a second
            // relation load: the disable confirmation needs it per row.
            ->withCount(['oeuvres as in_flight_oeuvres_count' => fn ($query) => $query
                ->whereIn('status', RegisterTypeCollege::IN_FLIGHT_OEUVRE_STATUSES)])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%")
                ->orWhere('code_college', 'like', "%{$search}%")))
            ->when($type > 0, fn ($query) => $query->where('register_type_id', $type))
            ->when($gestion > 0, fn ($query) => $query->where('type_gestion_id', $gestion))
            ->orderBy('register_type_id')
            ->orderBy('name')
            ->paginate($this->perPage($request, self::PER_PAGE))
            ->withQueryString();

        // Parent options for both the filters and the create form, with the
        // two flags the form needs per type — computed as aggregates in one
        // query, not by calling hasActiveGestions()/acceptsCodeDv() per type
        // (the same predicates, expressed as counts; asserted equal in
        // ReferentielCreateTest).
        $types = RegisterType::query()
            ->withCount(['typeGestions as active_gestions_count' => fn ($query) => $query->where('status', TypeGestion::STATUS_ACTIVE)])
            ->withCount('registerTypeColleges as colleges_count')
            ->withCount(['registerTypeColleges as coded_colleges_count' => fn ($query) => $query->whereNotNull('code_dv')])
            ->orderBy('id')
            ->get();
        $typeUuids = $types->pluck('uuid', 'id');
        $gestions = TypeGestion::query()->orderBy('register_type_id')->orderBy('type_gestion')->get();

        return $this->page('admin/referentiel/Colleges', [
            'rows' => $colleges->through(fn (RegisterTypeCollege $college): array => [
                'uuid' => $college->uuid,
                'code_college' => $college->code_college,
                'name' => $college->name,
                'name_ar' => $college->name_ar,
                'name_en' => $college->name_en,
                'type' => $college->registerType?->name_global,
                'gestion' => $college->typeGestion?->name_global,
                'members_count' => (int) $college['register_type_members_count'],
                'documents_count' => (int) $college['college_oeuvre_files_count'],
                'oeuvres_count' => (int) $college['oeuvres_count'],
                'in_flight_oeuvres_count' => (int) $college['in_flight_oeuvres_count'],
                'oeuvres_by_status' => $this->oeuvresByStatus($college),
                'is_system' => $college->is_system,
                'code_dv' => $college->code_dv,
                // For the "enable" refusal's link to the documents tab.
                'has_documents' => (int) $college['college_oeuvre_files_count'] > 0,
                'status' => $college->status,
                'is_disabled' => $college->is_disabled,
                'adhesion' => $college->adhesion,
                // Reference-only codes are never selectable whatever the
                // flags say — the UI marks them so nobody wonders why
                // enabling one changes nothing.
                'hidden_from_registration' => in_array(
                    $college->code_college,
                    RegisterTypeCollege::CODES_HIDDEN_FROM_REGISTRATION,
                    true,
                ),
            ]),
            'filters' => [
                'search' => $search,
                'type' => $type > 0 ? $request->query('type') : null,
                'gestion' => $gestion > 0 ? $request->query('gestion') : null,
            ],
            // Parent options for both the filters and the create form. Each
            // type says whether it has a gestion level and whether it takes a
            // `code_dv`, from the same predicates the server validates with.
            'types' => $types->map(fn (RegisterType $t): array => [
                'uuid' => $t->uuid,
                'name' => trim($t->name_global),
                'has_gestions' => (int) $t['active_gestions_count'] > 0,
                'accepts_code_dv' => ! ((int) $t['colleges_count'] > 0 && (int) $t['coded_colleges_count'] === 0),
            ]),
            'gestions' => $gestions->map(fn (TypeGestion $g): array => [
                'uuid' => $g->uuid,
                'type_uuid' => $typeUuids[$g->register_type_id] ?? null,
                'name' => trim($g->name_global),
                'active' => $g->status === TypeGestion::STATUS_ACTIVE,
            ]),
        ]);
    }

    public function store(StoreCollegeRequest $request, CreateCollege $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var RegisterType $type */
        $type = $request->registerType();

        $create->handle($actor, $type, $request->gestion(), $request->validated());

        return $this->created();
    }

    public function update(UpdateCollegeRequest $request, RegisterTypeCollege $college): RedirectResponse
    {
        $this->applyAndRecord($request, $college, $request->validated(), self::EDITABLE);

        return $this->saved();
    }

    /**
     * `oeuvres_{status}_count` aggregates, one per status.
     *
     * @return array<string, \Closure>
     */
    private static function statusCounts(): array
    {
        $counts = [];

        foreach (self::OEUVRE_STATUSES as $status) {
            $counts["oeuvres as oeuvres_{$status}_count"] = fn ($query) => $query->where('status', $status);
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function oeuvresByStatus(RegisterTypeCollege $college): array
    {
        $byStatus = [];

        foreach (self::OEUVRE_STATUSES as $status) {
            $count = (int) $college["oeuvres_{$status}_count"];

            if ($count > 0) {
                $byStatus[$status] = $count;
            }
        }

        return $byStatus;
    }
}
