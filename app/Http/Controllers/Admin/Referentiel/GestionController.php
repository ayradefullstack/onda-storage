<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Referentiel;

use App\Actions\Referentiel\CreateTypeGestion;
use App\Http\Requests\Admin\Referentiel\StoreGestionRequest;
use App\Http\Requests\Admin\Referentiel\UpdateGestionRequest;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\TypeGestion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Type-de-gestion labels — the optional second level of the cascade.
 *
 * A type shows the gestion select iff it has at least one ACTIVE gestion
 * (RegisterType::hasActiveGestions), so `status` here is not cosmetic:
 * retiring a type's last gestion collapses its flow to three levels.
 *
 * `type_gestion` (the integer 1/2/3 that colleges carry) and
 * `register_type_id` are structural and frozen. `name` is editable on an
 * admin-created gestion and refused on a system one.
 */
final class GestionController extends ReferentielController
{
    /** @var list<string> */
    public const EDITABLE = ['name', 'name_ar', 'name_en', 'status'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));

        $gestions = TypeGestion::query()
            ->with('registerType:id,uuid,name,name_ar,name_en')
            ->withCount('registerTypeColleges')
            ->withCount(['registerTypeColleges as reachable_colleges_count' => fn ($query) => $query
                ->where('status', RegisterTypeCollege::STATUS_ACTIVE)
                ->where('is_disabled', false)
                ->whereNotIn('code_college', RegisterTypeCollege::CODES_HIDDEN_FROM_REGISTRATION)])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%")))
            ->orderBy('register_type_id')
            ->orderBy('type_gestion')
            ->paginate($this->perPage($request, self::PER_PAGE))
            ->withQueryString();

        return $this->page('admin/referentiel/Gestions', [
            'rows' => $gestions->through(fn (TypeGestion $gestion): array => [
                'uuid' => $gestion->uuid,
                'name' => $gestion->name,
                'name_ar' => $gestion->name_ar,
                'name_en' => $gestion->name_en,
                'type_gestion' => $gestion->type_gestion,
                'type' => $gestion->registerType?->name_global,
                'colleges_count' => (int) $gestion['register_type_colleges_count'],
                'reachable_colleges_count' => (int) $gestion['reachable_colleges_count'],
                'status' => $gestion->status,
                'is_system' => $gestion->is_system,
            ]),
            'filters' => ['search' => $search],
            // The create form's parent select. The college count is the
            // "this changes that type's flow" warning for a type that has
            // colleges but no gestion yet.
            'types' => RegisterType::query()
                ->withCount('registerTypeColleges')
                ->withCount(['typeGestions as active_gestions_count' => fn ($query) => $query->where('status', TypeGestion::STATUS_ACTIVE)])
                ->orderBy('id')
                ->get()
                ->map(fn (RegisterType $type): array => [
                    'uuid' => $type->uuid,
                    'name' => trim($type->name_global),
                    'colleges_count' => (int) $type['register_type_colleges_count'],
                    'has_gestions' => (int) $type['active_gestions_count'] > 0,
                ]),
        ]);
    }

    public function store(StoreGestionRequest $request, CreateTypeGestion $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var RegisterType $type */
        $type = $request->registerType();

        $create->handle($actor, $type, $request->validated());

        return $this->created();
    }

    public function update(UpdateGestionRequest $request, TypeGestion $typeGestion): RedirectResponse
    {
        $this->applyAndRecord($request, $typeGestion, $request->validated(), self::EDITABLE);

        return $this->saved();
    }
}
