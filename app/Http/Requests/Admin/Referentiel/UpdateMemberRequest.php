<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\Concerns\ValidatesEditableName;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The three flags, the localised names, and `name` on an admin-created row.
 * `code_qlt` and `register_type_college_id` are absent by design:
 *
 * - `code_qlt` is how MembershipTypeSeeder::syncMember() matches a member
 *   across runs; changing it makes the next run create a duplicate.
 * - `register_type_college_id` moves the qualité to another college,
 *   invalidating every oeuvre filed under it.
 * - `name` of a SYSTEM row is seeder-owned and would be written back on the
 *   next run, so it is refused there (ValidatesEditableName).
 */
final class UpdateMemberRequest extends FormRequest
{
    use ValidatesEditableName;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var RegisterTypeMember $member */
        $member = $this->route('registerTypeMember');

        return [
            'name' => $this->nameRules($member, 'register_type_members', ['register_type_college_id' => $member->register_type_college_id]),
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'integer', Rule::in([0, RegisterTypeCollege::STATUS_ACTIVE])],
            'is_disabled' => ['sometimes', 'boolean'],
            'available_in_registration' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->systemNameMessages();
    }
}
