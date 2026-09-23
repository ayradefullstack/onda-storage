<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Models\RegisterTypeCollege;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only the three flags. `name`, `code_qlt` and `register_type_college_id`
 * are absent by design:
 *
 * - `code_qlt` is how MembershipTypeSeeder::syncMember() matches a member
 *   across runs; changing it makes the next run create a duplicate.
 * - `register_type_college_id` moves the qualité to another college,
 *   invalidating every oeuvre filed under it.
 * - `name` is seeder-owned and would be written back on the next run.
 */
final class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'integer', Rule::in([0, RegisterTypeCollege::STATUS_ACTIVE])],
            'is_disabled' => ['sometimes', 'boolean'],
            'available_in_registration' => ['sometimes', 'boolean'],
        ];
    }
}
