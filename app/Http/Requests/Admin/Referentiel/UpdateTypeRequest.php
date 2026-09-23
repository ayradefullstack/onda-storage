<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Referentiel;

use App\Models\RegisterTypeCollege;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `name` and `slug` are absent: MembershipTypeSeeder matches types on
 * `name` (firstOrCreate), and `slug` is what StoreOeuvreRequest reads to
 * decide whether the Auteur gestion step applies. Both are identity.
 */
final class UpdateTypeRequest extends FormRequest
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
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'integer', Rule::in([0, RegisterTypeCollege::STATUS_ACTIVE])],
            'is_disabled' => ['sometimes', 'boolean'],
        ];
    }
}
