<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Models\CollegeOeuvreFile;
use App\Models\RegisterTypeCollege;
use App\Support\FileFormats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a required-document slot for a college.
 *
 * `document_key` is chosen here and frozen. Extensions come from the format
 * registry's whitelist (the same rules as UpdateDocumentRequest); MIME types
 * are NEVER accepted — the model derives them, so an admin cannot type one.
 */
final class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('document_key'))) {
            $this->merge(['document_key' => trim($this->input('document_key'))]);
        }

        if (is_string($this->input('title'))) {
            $this->merge(['title' => trim($this->input('title'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'college' => ['required', 'string', Rule::exists('register_type_colleges', 'uuid')->whereNull('deleted_at')],
            'document_key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'title' => ['required', 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'title_en' => ['nullable', 'string', 'max:255'],
            'extensions' => ['required', 'array', 'min:1', 'max:60'],
            'extensions.*' => ['string', 'max:12', 'regex:/^[a-z0-9]+$/', Rule::in(FileFormats::extensions())],
            'is_required' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'max_size_kb' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5242880'],
            'allows_multiple' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'document_key.regex' => 'admin.referentiel.errors.keyShape',
            'extensions.*.regex' => 'Each extension must be lowercase letters or digits only — no dot, no MIME type, no spaces.',
            'extensions.*.in' => 'That format is not one this application can accept. Executable and script formats are deliberately absent from the registry.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $college = $this->college();

            if ($college !== null && is_string($this->input('document_key'))
                // Retired rows count: the unique index covers them.
                && CollegeOeuvreFile::withTrashed()
                    ->where('register_type_college_id', $college->id)
                    ->where('document_key', $this->input('document_key'))
                    ->exists()) {
                $validator->errors()->add('document_key', 'admin.referentiel.errors.keyTaken');
            }

            $extensions = $this->input('extensions');

            if (is_array($extensions)) {
                $bare = array_filter($extensions, 'is_string');

                if (count($bare) !== count(array_unique($bare))) {
                    $validator->errors()->add('extensions', 'The same extension is listed more than once.');
                }
            }
        });
    }

    public function college(): ?RegisterTypeCollege
    {
        $uuid = $this->input('college');

        return is_string($uuid) ? RegisterTypeCollege::query()->where('uuid', $uuid)->first() : null;
    }
}
