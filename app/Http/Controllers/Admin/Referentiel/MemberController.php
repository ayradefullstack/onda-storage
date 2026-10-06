<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Referentiel;

use App\Actions\Referentiel\CreateMember;
use App\Http\Requests\Admin\Referentiel\StoreMemberRequest;
use App\Http\Requests\Admin\Referentiel\UpdateMemberRequest;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Qualités within a college — 100 rows, the densest tab.
 *
 * `code_qlt` is how MembershipTypeSeeder matches a member across runs, so
 * it is frozen along with the parent college. `name` is editable on an
 * admin-created qualité and refused on a system one. The three flags are
 * the point of this tab; the UI labels each one distinctly because their
 * meanings genuinely differ.
 */
final class MemberController extends ReferentielController
{
    /** @var list<string> */
    public const EDITABLE = ['name', 'name_ar', 'name_en', 'status', 'is_disabled', 'available_in_registration'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->string('search'));
        // The college filter travels as a uuid — no sequential id in a URL.
        $college = $this->idForUuid(RegisterTypeCollege::class, $request->query('college')) ?? 0;

        $members = RegisterTypeMember::query()
            ->with('registerTypeCollege:id,uuid,name,name_ar,name_en,code_college')
            ->withCount('oeuvres')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code_qlt', 'like', "%{$search}%")))
            ->when($college > 0, fn ($query) => $query->where('register_type_college_id', $college))
            ->orderBy('register_type_college_id')
            ->orderBy('id')
            ->paginate($this->perPage($request, self::PER_PAGE))
            ->withQueryString();

        return $this->page('admin/referentiel/Membres', [
            'rows' => $members->through(fn (RegisterTypeMember $member): array => [
                'uuid' => $member->uuid,
                'name' => $member->name,
                'name_ar' => $member->name_ar,
                'name_en' => $member->name_en,
                'is_system' => $member->is_system,
                'oeuvres_count' => (int) $member['oeuvres_count'],
                'code_qlt' => $member->code_qlt,
                'college' => $member->registerTypeCollege?->name_global,
                'code_college' => $member->registerTypeCollege?->code_college,
                'status' => $member->status,
                'is_disabled' => $member->is_disabled,
                'available_in_registration' => $member->available_in_registration,
            ]),
            'filters' => ['search' => $search, 'college' => $college > 0 ? $request->query('college') : null],
            // Every college, not only those that already have members: a
            // new college must be selectable here to get its first qualité.
            'colleges' => RegisterTypeCollege::query()
                ->orderBy('name')
                ->get(['id', 'uuid', 'name', 'name_ar', 'name_en'])
                ->map(fn (RegisterTypeCollege $c): array => ['uuid' => $c->uuid, 'name' => trim($c->name_global)]),
        ]);
    }

    public function store(StoreMemberRequest $request, CreateMember $create): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var RegisterTypeCollege $college */
        $college = $request->college();

        $create->handle($actor, $college, $request->validated());

        return $this->created();
    }

    public function update(UpdateMemberRequest $request, RegisterTypeMember $registerTypeMember): RedirectResponse
    {
        $this->applyAndRecord($request, $registerTypeMember, $request->validated(), self::EDITABLE);

        return $this->saved();
    }
}
