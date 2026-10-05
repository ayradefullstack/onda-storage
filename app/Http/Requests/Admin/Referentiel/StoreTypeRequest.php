<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\Rules\UniqueTrimmedName;
use App\Models\RegisterType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a declarant type. Admin-only through the `role:admin` route
 * group — reference data belongs to the office, not to a row owner.
 *
 * No `slug`, no `is_system`, no `is_disabled`: the slug is generated from
 * the name by CreateRegisterType and frozen, and the other two are set by
 * the application, never by a request.
 */
final class StoreTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new UniqueTrimmedName('register_types', [])],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'integer', Rule::in([0, RegisterType::STATUS_ACTIVE])],
        ];
    }
}
