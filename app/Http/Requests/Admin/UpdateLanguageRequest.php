<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Language;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edits a language. `code` is absent on purpose: it is baked into cookies,
 * `/{locale}` URLs and the `locales/{code}.json` bundle, so renaming it
 * would orphan all three. `is_default` is absent too — see
 * LanguageService::setDefault. The "default must stay active" rule is
 * enforced by the controller through the service, not here.
 */
final class UpdateLanguageRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'native_name' => ['sometimes', 'required', 'string', 'max:100'],
            'direction' => ['sometimes', 'required', Rule::in(Language::DIRECTIONS)],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
