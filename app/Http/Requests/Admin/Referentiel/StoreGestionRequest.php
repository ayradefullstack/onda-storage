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
 * Creating a type de gestion. The parent is named by uuid and, once saved,
 * never changes. `type_gestion` (the 1/2/3 integer) is not accepted: it is
 * assigned, so a request cannot pick a value that collides.
 */
final class StoreGestionRequest extends FormRequest
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
        $type = $this->registerType();

        return [
            'register_type' => ['required', 'string', Rule::exists('register_types', 'uuid')->whereNull('deleted_at')],
            'name' => [
                'bail', 'required', 'string', 'max:255',
                new UniqueTrimmedName('type_gestions', ['register_type_id' => $type->id ?? 0]),
            ],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'integer', Rule::in([0, TypeGestion::STATUS_ACTIVE])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = $this->registerType();

            if ($type !== null && TypeGestion::withTrashed()->where('register_type_id', $type->id)->count() >= count(TypeGestion::VALUES)) {
                $validator->errors()->add('register_type', 'admin.referentiel.errors.gestionLimit');
            }
        });
    }

    public function registerType(): ?RegisterType
    {
        $uuid = $this->input('register_type');

        return is_string($uuid) ? RegisterType::query()->where('uuid', $uuid)->first() : null;
    }
}
