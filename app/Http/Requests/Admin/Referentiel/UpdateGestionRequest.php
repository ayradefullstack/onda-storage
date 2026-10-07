<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\Concerns\ValidatesEditableName;
use App\Models\TypeGestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Localised labels, the status, and `name` on an admin-created row.
 * `type_gestion` is the integer 1/2/3 a college carries and resolves its
 * gestion through; `register_type_id` binds the gestion to its type. Both
 * are structural and absent from rules().
 */
final class UpdateGestionRequest extends FormRequest
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
        /** @var TypeGestion $gestion */
        $gestion = $this->route('typeGestion');

        return [
            'name' => $this->nameRules($gestion, 'type_gestions', ['register_type_id' => $gestion->register_type_id]),
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'integer', Rule::in([0, TypeGestion::STATUS_ACTIVE])],
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
