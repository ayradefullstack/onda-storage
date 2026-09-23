<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\UpdateGestionRequest;
use App\Models\TypeGestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Type-de-gestion labels — the Auteur branch's second level. Three rows;
 * only their localised names are editable. `type_gestion` (the integer
 * 1/2/3 that colleges carry) and `register_type_id` are structural: a
 * college resolves its gestion through them.
 */
final class GestionController extends ReferentielController
{
    /** @var list<string> */
    public const EDITABLE = ['name_ar', 'name_en'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));

        $gestions = TypeGestion::query()
            ->with('registerType:id,name,name_ar,name_en')
            ->withCount('registerTypeColleges')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%")))
            ->orderBy('type_gestion')
            ->paginate(self::PER_PAGE)
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
            ]),
            'filters' => ['search' => $search],
        ]);
    }

    public function update(UpdateGestionRequest $request, TypeGestion $typeGestion): RedirectResponse
    {
        $this->applyAndRecord($request, $typeGestion, $request->validated(), self::EDITABLE);

        return $this->saved();
    }
}
