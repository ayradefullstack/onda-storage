<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\UpdateMemberRequest;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Qualités within a college — 100 rows, the densest tab.
 *
 * `code_qlt` is how MembershipTypeSeeder matches a member across runs, so
 * it is seeder-owned along with `name` and the parent college. The three
 * flags are the point of this tab; the UI labels each one distinctly
 * because their meanings genuinely differ.
 */
final class MemberController extends ReferentielController
{
    /** @var list<string> */
    public const EDITABLE = ['status', 'is_disabled', 'available_in_registration'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        $college = (int) $request->integer('college');

        $members = RegisterTypeMember::query()
            ->with('registerTypeCollege:id,name,name_ar,name_en,code_college')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code_qlt', 'like', "%{$search}%")))
            ->when($college > 0, fn ($query) => $query->where('register_type_college_id', $college))
            ->orderBy('register_type_college_id')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return $this->page('admin/referentiel/Membres', [
            'rows' => $members->through(fn (RegisterTypeMember $member): array => [
                'uuid' => $member->uuid,
                'name' => $member->name,
                'code_qlt' => $member->code_qlt,
                'college' => $member->registerTypeCollege?->name_global,
                'code_college' => $member->registerTypeCollege?->code_college,
                'status' => $member->status,
                'is_disabled' => $member->is_disabled,
                'available_in_registration' => $member->available_in_registration,
            ]),
            'filters' => ['search' => $search, 'college' => $college ?: null],
            'colleges' => RegisterTypeCollege::query()
                ->whereHas('registerTypeMembers')
                ->orderBy('name')
                ->get(['id', 'name', 'name_ar', 'name_en'])
                ->map(fn (RegisterTypeCollege $c): array => ['id' => $c->id, 'name' => trim($c->name_global)]),
        ]);
    }

    public function update(UpdateMemberRequest $request, RegisterTypeMember $registerTypeMember): RedirectResponse
    {
        $this->applyAndRecord($request, $registerTypeMember, $request->validated(), self::EDITABLE);

        return $this->saved();
    }
}
