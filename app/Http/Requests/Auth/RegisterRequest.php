<?php

namespace App\Http\Requests\Auth;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Country;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (config('fortify.lowercase_usernames') && is_string($email)) {
            $this->merge(['email' => mb_strtolower($email)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $dzId = Country::dz()->value('id');
        $countryId = $this->idInput('country_id');
        $wilayaId = $this->idInput('wilaya_id');
        $requiredInDz = Rule::requiredIf(fn (): bool => $dzId !== null && $countryId === (int) $dzId);

        // Mirrors Country/Wilaya/Commune::scopeActive() + scopeVisible() + SoftDeletes.
        $selectable = fn (Builder $query): Builder => $query
            ->where('is_active', true)
            ->where('is_visible', true)
            ->whereNull('deleted_at');

        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'first_name_ar' => ['required', 'string', 'max:120', 'regex:/^[\x{0600}-\x{06FF}\s]+$/u'],
            'last_name_ar' => ['required', 'string', 'max:120', 'regex:/^[\x{0600}-\x{06FF}\s]+$/u'],
            'country_id' => [
                'required', 'bail', 'integer',
                Rule::exists('countries', 'id')->where($selectable),
            ],
            'wilaya_id' => [
                'bail', 'nullable', 'integer', $requiredInDz,
                Rule::exists('wilayas', 'id')->where(
                    fn (Builder $query): Builder => $selectable($query)->where('country_id', $countryId)
                ),
            ],
            'commune_id' => [
                'bail', 'nullable', 'integer', $requiredInDz,
                Rule::exists('communes', 'id')->where(
                    fn (Builder $query): Builder => $selectable($query)->where('wilaya_id', $wilayaId)
                ),
            ],
            'city' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * Submitted id as an int; anything non-scalar or non-numeric becomes 0
     * so it can never reach a where clause.
     */
    private function idInput(string $key): int
    {
        $value = $this->input($key);

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }
}
