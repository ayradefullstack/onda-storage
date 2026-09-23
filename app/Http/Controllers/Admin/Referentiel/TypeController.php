<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\UpdateTypeRequest;
use App\Models\RegisterType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Declarant types — four rows, the top of the classification cascade.
 * `name` and `slug` are seeder-owned identity; only presentation and the
 * two behaviour flags are editable.
 */
final class TypeController extends ReferentielController
{
    /** @var list<string> */
    public const EDITABLE = ['name_ar', 'name_en', 'status', 'is_disabled'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));

        $types = RegisterType::query()
            ->withCount(['registerTypeColleges', 'typeGestions'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%")))
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return $this->page('admin/referentiel/Types', [
            'rows' => $types->through(fn (RegisterType $type): array => [
                'uuid' => $type->uuid,
                'name' => $type->name,
                'name_ar' => $type->name_ar,
                'name_en' => $type->name_en,
                'colleges_count' => (int) $type['register_type_colleges_count'],
                'gestions_count' => (int) $type['type_gestions_count'],
                'status' => $type->status,
                'is_disabled' => $type->is_disabled,
            ]),
            'filters' => ['search' => $search],
        ]);
    }

    public function update(UpdateTypeRequest $request, RegisterType $registerType): RedirectResponse
    {
        $this->applyAndRecord($request, $registerType, $request->validated(), self::EDITABLE);

        return $this->saved();
    }
}
