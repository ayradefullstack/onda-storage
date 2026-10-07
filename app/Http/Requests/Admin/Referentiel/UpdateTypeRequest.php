<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\Concerns\ValidatesEditableName;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `slug` is absent: it is generated at creation and frozen. `name` is
 * editable on an admin-created type and refused on a system one (the
 * seeder matches types on it) — see ValidatesEditableName.
 */
final class UpdateTypeRequest extends FormRequest
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
        /** @var RegisterType $type */
        $type = $this->route('registerType');

        return [
            'name' => $this->nameRules($type, 'register_types', []),
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'integer', Rule::in([0, RegisterTypeCollege::STATUS_ACTIVE])],
            'is_disabled' => ['sometimes', 'boolean'],
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
