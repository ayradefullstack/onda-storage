<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\UpdateCollegeRequest;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\TypeGestion;
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
     * `code_college`, `name`, `register_type_id`, `type_gestion*`,
     * `code_dv` — is seeder-owned identity and is rendered read-only with
     * its reason (see UpdateCollegeRequest).
     *
     * @var list<string>
     */
    public const EDITABLE = ['name_ar', 'name_en', 'status', 'is_disabled', 'adhesion'];

    /**
     * Query budget — 8, flat, whatever the page size (asserted in
     * ReferentielTablesTest):
     *   1 paginator count, 2 the page itself with its four withCount
     *     aggregates folded in, 3+4 the two eager-loaded parents,
     *   5-9 minus one: the five tab counts share the request with the
     *     filter dropdowns' two lookups.
     * The four counts are `withCount`, never a relation load: 22 colleges
     * each lazy-loading members would be 22 extra queries, and the members
     * tab would be 100.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        $type = (int) $request->integer('type');
        $gestion = (int) $request->integer('gestion');

        $colleges = RegisterTypeCollege::query()
            ->with(['registerType:id,name,name_ar,name_en', 'typeGestion:id,name,name_ar,name_en'])
            ->withCount(['registerTypeMembers', 'collegeOeuvreFiles', 'oeuvres'])
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
            ->paginate(self::PER_PAGE)
            ->withQueryString();

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
            'filters' => ['search' => $search, 'type' => $type ?: null, 'gestion' => $gestion ?: null],
            'types' => RegisterType::query()->orderBy('id')->get(['id', 'name', 'name_ar', 'name_en'])
                ->map(fn (RegisterType $t): array => ['id' => $t->id, 'name' => trim($t->name_global)]),
            'gestions' => TypeGestion::query()->orderBy('type_gestion')->get(['id', 'name', 'name_ar', 'name_en'])
                ->map(fn (TypeGestion $g): array => ['id' => $g->id, 'name' => trim($g->name_global)]),
        ]);
    }

    public function update(UpdateCollegeRequest $request, RegisterTypeCollege $college): RedirectResponse
    {
        $this->applyAndRecord($request, $college, $request->validated(), self::EDITABLE);

        return $this->saved();
    }
}
