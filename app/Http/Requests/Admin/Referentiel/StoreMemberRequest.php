<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\Rules\UniqueTrimmedName;
use App\Models\RegisterTypeCollege;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a qualité. `code_qlt` is optional; when present it must be unique
 * within the college, retired rows included — it is the seeder's first match
 * key and, once saved, never changes.
 */
final class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['name', 'code_qlt'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }

        if (isset($merge['code_qlt']) && $merge['code_qlt'] === '') {
            $merge['code_qlt'] = null;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $college = $this->college();

        return [
            'college' => ['bail', 'required', 'string', Rule::exists('register_type_colleges', 'uuid')->whereNull('deleted_at')],
            'name' => [
                'bail', 'required', 'string', 'max:255',
                new UniqueTrimmedName('register_type_members', ['register_type_college_id' => $college->id ?? 0]),
            ],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'code_qlt' => [
                'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_]+$/',
                Rule::unique('register_type_members', 'code_qlt')->where('register_type_college_id', $college->id ?? 0),
            ],
            'available_in_registration' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'integer', Rule::in([0, RegisterTypeCollege::STATUS_ACTIVE])],
        ];
    }

    public function messages(): array
    {
        return [
            'code_qlt.regex' => 'admin.referentiel.errors.codeShape',
            'code_qlt.unique' => 'admin.referentiel.errors.codeTaken',
        ];
    }

    public function college(): ?RegisterTypeCollege
    {
        $uuid = $this->input('college');

        return is_string($uuid) ? RegisterTypeCollege::query()->where('uuid', $uuid)->first() : null;
    }
}
