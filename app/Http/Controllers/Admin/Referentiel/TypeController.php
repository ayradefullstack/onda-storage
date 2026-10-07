<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Referentiel;

use App\Actions\Referentiel\CreateRegisterType;
use App\Http\Requests\Admin\Referentiel\StoreTypeRequest;
use App\Http\Requests\Admin\Referentiel\UpdateTypeRequest;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Declarant types — the top of the classification cascade.
 *
 * `slug` is generated at creation and frozen. `name` is editable on an
 * admin-created type and refused on a system one (the seeder matches types
 * on it). No destroy: a type with colleges is retired with its flags.
 */
final class TypeController extends ReferentielController
{
    /** @var list<string> */
    public const EDITABLE = ['name', 'name_ar', 'name_en', 'status', 'is_disabled'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));

        $types = RegisterType::query()
            ->withCount(['registerTypeColleges', 'typeGestions'])
            // The blast radius of retiring a type: the colleges step 1
            // would stop offering. Mirrors RegisterTypeCollege::isReachable.
            ->withCount(['registerTypeColleges as reachable_colleges_count' => fn ($query) => $query
                ->where('status', RegisterTypeCollege::STATUS_ACTIVE)
                ->where('is_disabled', false)
                ->whereNotIn('code_college', RegisterTypeCollege::CODES_HIDDEN_FROM_REGISTRATION)])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_ar', 'like', "%{$search}%")
                ->orWhere('name_en', 'like', "%{$search}%")))
            ->orderBy('id')
            ->paginate($this->perPage($request, self::PER_PAGE))
            ->withQueryString();

        return $this->page('admin/referentiel/Types', [
            'rows' => $types->through(fn (RegisterType $type): array => [
                'uuid' => $type->uuid,
                'name' => $type->name,
                'name_ar' => $type->name_ar,
                'name_en' => $type->name_en,
                'colleges_count' => (int) $type['register_type_colleges_count'],
                'reachable_colleges_count' => (int) $type['reachable_colleges_count'],
                'gestions_count' => (int) $type['type_gestions_count'],
                'status' => $type->status,
                'is_disabled' => $type->is_disabled,
                'is_system' => $type->is_system,
            ]),
            'filters' => ['search' => $search],
        ]);
    }

    public function store(StoreTypeRequest $request, CreateRegisterType $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $create->handle($actor, $request->validated());

        return $this->created();
    }

    public function update(UpdateTypeRequest $request, RegisterType $registerType): RedirectResponse
    {
        $this->applyAndRecord($request, $registerType, $request->validated(), self::EDITABLE);

        return $this->saved();
    }
}
