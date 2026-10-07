<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /uploads/{session}/complete` takes no body — everything it needs
 * (which chunks arrived) lives in the chunk tracker, keyed by the route's
 * `{session:uuid}` binding. This class exists so the controller signature
 * matches the rest of the upload endpoints and so a future required field
 * (e.g. a client-declared final checksum) has an obvious home.
 */
final class CompleteUploadRequest extends FormRequest
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
        return [];
    }
}
