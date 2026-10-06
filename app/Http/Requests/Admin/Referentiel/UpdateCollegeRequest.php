<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\Concerns\ValidatesEditableName;
use App\Models\RegisterTypeCollege;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
 * `type_gestion`, `type_gestion_id`, `code_dv` — structure, frozen.
 *
 * `name` — editable on an ADMIN-created college, refused on a SYSTEM one:
 *   MembershipTypeSeeder matches system colleges on
 *   (register_type_id, name, type_gestion), so a renamed system college
 *   would be unmatched and duplicated on the next run. Admin rows are never
 *   matched by the seeder. See ValidatesEditableName.
 *
 * ENABLING (D4): a college cannot be made reachable in step 1 unless it has
 *   at least one active required-document row, because InitUpload demands a
 *   requirement slot for a classified oeuvre — an empty college would let an
 *   author classify and then be unable to upload anything. The refusal
 *   carries the college's uuid so the page can link to the documents tab
 *   pre-filtered to it.
 *
 * They are absent from `rules()` rather than rejected with a message:
 * anything not listed is never read, so a crafted request carrying
 * `code_college` changes nothing. The UI renders them read-only WITH their
 * reason, so an admin sees the key and understands why it is fixed.
 */
final class UpdateCollegeRequest extends FormRequest
{
    use ValidatesEditableName;

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
        /** @var RegisterTypeCollege $college */
        $college = $this->route('college');

        return [
            'name' => $this->nameRules($college, 'register_type_colleges', [
                'register_type_id' => $college->register_type_id,
                'type_gestion_id' => $college->type_gestion_id,
            ]),
            // Nullable on purpose: clearing a translation is legitimate and
            // falls the display back to the French `name` (name_global).
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'integer', Rule::in([0, RegisterTypeCollege::STATUS_ACTIVE])],
            'is_disabled' => ['sometimes', 'boolean'],
            'adhesion' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->systemNameMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var RegisterTypeCollege $college */
            $college = $this->route('college');

            $wasReachable = $college->isReachable();

            $after = clone $college;
            $after->status = $this->has('status') ? $this->integer('status') : $college->status;
            $after->is_disabled = $this->has('is_disabled') ? $this->boolean('is_disabled') : $college->is_disabled;

            if (! $wasReachable && $after->isReachable() && $college->activeDocumentsCount() === 0) {
                $validator->errors()->add('is_disabled', 'admin.referentiel.errors.collegeNoDocuments');
                $validator->errors()->add('college', $college->uuid);
            }
        });
    }
}
