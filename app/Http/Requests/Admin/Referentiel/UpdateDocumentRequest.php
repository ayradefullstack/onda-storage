<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Support\FileFormats;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ---------------------------------------------------------------------
 * What this request deliberately does NOT accept, and why.
 * ---------------------------------------------------------------------
 * `document_key` — frozen into `media_files.document_key_snapshot`, so a
 *   deposited file records the slot it was filed under. Renaming the key
 *   makes every one of those snapshots a lie.
 *
 * `register_type_college_id` — moves the requirement to another college,
 *   invalidating deposits under both.
 *
 * `title` (French) and `conditions` — seeder-owned. `title` is the label
 *   ONDA's matrix defines; `conditions` is ONDA's show_when/hide_when
 *   expression kept verbatim and not yet evaluated by anything.
 *
 * Absent from `rules()` rather than rejected: unlisted input is never
 * read, so a crafted request carrying `document_key` changes nothing.
 */
final class UpdateDocumentRequest extends FormRequest
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
            'title_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_en' => ['sometimes', 'nullable', 'string', 'max:255'],

            // A stored list of bare lowercase extensions. Every rule below
            // exists because the seeder had to derive this list from
            // ONDA's mixed `File::types` / `mimetypes:` rules, and the
            // failure modes are exactly those shapes leaking back in.
            'extensions' => ['sometimes', 'array', 'min:1', 'max:60'],
            // Rule::in over the registry is the real gate: it rejects `exe`,
            // `php` and anything invented, because the registry is a
            // whitelist and absence is refusal. The regex stays in front of
            // it so a malformed value gets a message about its shape rather
            // than "invalid selection".
            'extensions.*' => [
                'string',
                'max:12',
                'regex:/^[a-z0-9]+$/',
                Rule::in(FileFormats::extensions()),
            ],

            'is_required' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:1', 'max:999'],
            // Nullable is "no limit", which is what most rows carry.
            'max_size_kb' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5242880'],
            'allows_multiple' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The regex rejects dots, uppercase, slashes and spaces in one
            // go, so the message names all three shapes an officer is
            // likely to paste in: ".PDF", "application/pdf", "pdf, jpg".
            'extensions.*.regex' => 'Each extension must be lowercase letters or digits only — no dot, no MIME type, no spaces (e.g. "pdf", not ".PDF" or "application/pdf").',
            'extensions.*.in' => 'That format is not one this application can accept. Executable and script formats are deliberately absent from the registry.',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $extensions = $this->input('extensions');

            if (! is_array($extensions)) {
                return;
            }

            // Duplicates are not a validation error anywhere else in this
            // codebase, but a slot accepting ["pdf","pdf"] renders a
            // nonsense accept list to the author.
            $bare = array_filter($extensions, 'is_string');

            if (count($bare) !== count(array_unique($bare))) {
                $validator->errors()->add('extensions', 'The same extension is listed more than once.');
            }
        });
    }
}
