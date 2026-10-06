<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Language;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates a language. `is_default` is not accepted: a new language is never
 * the default (LanguageService::setDefault is the only way to become one).
 */
final class StoreLanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // `role:admin` on the route group is the boundary.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtolower(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:12', 'regex:/^'.Language::CODE_PATTERN.'$/', Rule::unique('languages', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'native_name' => ['required', 'string', 'max:100'],
            'direction' => ['required', Rule::in(Language::DIRECTIONS)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
