<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Models\RegisterTypeCollege;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ---------------------------------------------------------------------
 * What this request deliberately does NOT accept, and why.
 * ---------------------------------------------------------------------
 * `code_college` — the join key CollegeOeuvreFileSeeder resolves against,
 *   and the value frozen into `oeuvres.code_college_snapshot`. Changing it
 *   orphans this college's document requirements and turns every existing
 *   snapshot into a reference to a code no row carries.
 *
 * `register_type_id` — moves the college between declarant types,
 *   invalidating the classification of every oeuvre already filed under it.
 *
 * `name`, `type_gestion`, `type_gestion_id`, `code_dv` — seeder-owned
 *   identity. MembershipTypeSeeder matches colleges on
 *   (register_type_id, name, type_gestion) and NULLs every `code_college`
 *   before re-assigning, so a renamed college would be left uncoded and a
 *   duplicate created on the next run. See that seeder's docblock.
 *
 * They are absent from `rules()` rather than rejected with a message:
 * anything not listed is never read, so a crafted request carrying
 * `code_college` changes nothing. The UI renders them read-only WITH their
 * reason, so an admin sees the key and understands why it is fixed.
 */
final class UpdateCollegeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The `role:admin` middleware on the route group is the boundary;
        // there is no per-row ownership here — reference data belongs to
        // the office, not to an officer.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Nullable on purpose: clearing a translation is legitimate and
            // falls the display back to the French `name` (name_global).
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'integer', Rule::in([0, RegisterTypeCollege::STATUS_ACTIVE])],
            'is_disabled' => ['sometimes', 'boolean'],
            'adhesion' => ['sometimes', 'boolean'],
        ];
    }
}
