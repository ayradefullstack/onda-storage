<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Localised labels only. `type_gestion` is the integer 1/2/3 a college
 * carries and resolves its gestion through; `register_type_id` binds the
 * label to the Auteur branch. Both are structural.
 */
final class UpdateGestionRequest extends FormRequest
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
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
