<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Primitive-shape validation only (types, presence, size ceiling). The
 * extension/mime whitelist and "does this work belong to me" checks are
 * business rules that belong in the InitUpload action — a FormRequest can't
 * express a lookup-table validation or a policy check against a model that
 * only exists after the `work_id` itself has been validated.
 */
final class InitUploadRequest extends FormRequest
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
            'work_id' => ['required', 'integer', 'exists:works,id'],
            'filename' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.config('vault.max_file_size')],
            'mime' => ['required', 'string', 'max:128'],
        ];
    }
}
