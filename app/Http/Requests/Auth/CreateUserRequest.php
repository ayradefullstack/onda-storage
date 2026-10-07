<?php

namespace App\Http\Requests\Auth;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Country;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CreateUserRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $algeriaId = Country::dz()->first()?->id;

        return [
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'first_name_ar' => ['required', 'string', 'max:120', 'regex:/^[\x{0600}-\x{06FF}\s]+$/u'],
            'last_name_ar' => ['required', 'string', 'max:120', 'regex:/^[\x{0600}-\x{06FF}\s]+$/u'],
            'country_id' => ['required', 'exists:countries,id'],
            'wilaya_id' => ['nullable', $algeriaId ? 'required_if:country_id,'.$algeriaId : 'nullable', 'exists:wilayas,id'],
            'commune_id' => ['nullable', $algeriaId ? 'required_if:country_id,'.$algeriaId : 'nullable', 'exists:communes,id'],
            'city' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ];
    }

    public function messages()
    {
        return [
            'first_name.required' => 'يرجى إدخال الاسم',
            'last_name.required' => 'يرجى إدخال اللقب',
            'first_name_ar.required' => 'يرجى إدخال الاسم',
            'last_name_ar.required' => 'يرجى إدخال اللقب',
            'country_id.required' => 'يرجى إدخال البلد',
            'wilaya_id.required' => 'يرجى إدخال الولاية',
            'commune_id.required' => 'يرجى إدخال البلدية',
            'city.required' => 'يرجى إدخال المدينة',
            'phone.required' => 'يرجى إدخال رقم الهاتف',
            'email.required' => 'يرجى إدخال البريد الإلكتروني',
            'password.required' => 'يرجى إدخال كلمة المرور',
        ];
    }

    public function attributes()
    {
        return [
            'first_name' => 'الاسم',
            'last_name' => 'اللقب',
            'first_name_ar' => 'الاسم',
            'last_name_ar' => 'اللقب',
            'country_id' => 'البلد',
            'wilaya_id' => 'الولاية',
            'commune_id' => 'البلدية',
            'city' => 'المدينة',
            'phone' => 'رقم الهاتف',
            'email' => 'البريد الإلكتروني',
            'password' => 'كلمة المرور',
        ];
    }
}
