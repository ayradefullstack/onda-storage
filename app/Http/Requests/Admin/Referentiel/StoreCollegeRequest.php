<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Http\Requests\Admin\Referentiel\Rules\UniqueTrimmedName;
use App\Models\RegisterType;
use App\Models\TypeGestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a collège.
 *
 * The gestion rule is the same predicate step 1 uses
 * (RegisterType::hasActiveGestions): a type that has active gestions
 * REQUIRES one on its colleges, a type without them FORBIDS one. Nothing
 * here knows a slug or an id.
 *
 * `code_college` is checked against the table WITHOUT a `deleted_at`
 * filter: the unique index covers retired colleges, and a retired code is
 * still the value frozen into the snapshots of oeuvres filed under it.
 *
 * Not accepted from a request: `is_disabled` (always created disabled,
 * see CreateCollege), `status`, `is_system`, `type_gestion` (derived from
 * the gestion).
 */
final class StoreCollegeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['name', 'code_college', 'code_dv'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }

        if (isset($merge['code_dv']) && $merge['code_dv'] === '') {
            $merge['code_dv'] = null;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->registerType();
        $gestion = $this->gestion();

        return [
            'register_type' => ['bail', 'required', 'string', Rule::exists('register_types', 'uuid')->whereNull('deleted_at')],
            'type_gestion' => ['nullable', 'string', Rule::exists('type_gestions', 'uuid')->whereNull('deleted_at')],
            'code_college' => [
                'required', 'string', 'max:100', 'regex:/^[A-Z0-9_]+$/',
                Rule::unique('register_type_colleges', 'code_college'),
            ],
            'code_dv' => ['nullable', 'string', 'max:100', 'regex:/^[A-Z0-9_]+$/'],
            'name' => [
                'bail', 'required', 'string', 'max:255',
                new UniqueTrimmedName('register_type_colleges', [
                    'register_type_id' => $type->id ?? 0,
                    'type_gestion_id' => $gestion?->id,
                ]),
            ],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'adhesion' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code_college.regex' => 'admin.referentiel.errors.codeShape',
            'code_college.unique' => 'admin.referentiel.errors.codeTaken',
            'code_dv.regex' => 'admin.referentiel.errors.codeShape',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('register_type')) {
                return;
            }

            $type = $this->registerType();

            if ($type === null) {
                return;
            }

            $gestion = $this->gestion();

            if ($type->hasActiveGestions()) {
                if ($gestion === null) {
                    $validator->errors()->add('type_gestion', 'admin.referentiel.errors.gestionRequired');
                } elseif ($gestion->register_type_id !== $type->id || $gestion->status !== TypeGestion::STATUS_ACTIVE) {
                    $validator->errors()->add('type_gestion', 'admin.referentiel.errors.gestionWrongType');
                }
            } elseif ($this->filled('type_gestion')) {
                $validator->errors()->add('type_gestion', 'admin.referentiel.errors.gestionNotAllowed');
            }

            if ($this->filled('code_dv') && ! $type->acceptsCodeDv()) {
                $validator->errors()->add('code_dv', 'admin.referentiel.errors.codeDvNotAllowed');
            }
        });
    }

    public function registerType(): ?RegisterType
    {
        $uuid = $this->input('register_type');

        return is_string($uuid) ? RegisterType::query()->where('uuid', $uuid)->first() : null;
    }

    public function gestion(): ?TypeGestion
    {
        $uuid = $this->input('type_gestion');

        return is_string($uuid) && $uuid !== '' ? TypeGestion::query()->where('uuid', $uuid)->first() : null;
    }
}
