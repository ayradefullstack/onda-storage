<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\Models\Role;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $algeria = Country::where('alpha2', 'DZ')->first();
        $algeriaId = $algeria?->id;

        Validator::make($input, [
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
        ], [
            'first_name_ar.regex' => 'حقل الاسم بالعربية يجب أن يحتوي على أحرف عربية فقط.',
            'last_name_ar.regex' => 'حقل اللقب بالعربية يجب أن يحتوي على أحرف عربية فقط.',
            'wilaya_id.required_if' => 'يرجى اختيار الولاية.',
            'commune_id.required_if' => 'يرجى اختيار البلدية.',
        ])->validate();

        $fullName = trim(($input['first_name'] ?? '').' '.($input['last_name'] ?? ''));
        if (empty($fullName)) {
            $fullName = trim(($input['first_name_ar'] ?? '').' '.($input['last_name_ar'] ?? ''));
        }

        $user = User::create([
            'first_name' => $input['first_name'] ?? null,
            'last_name' => $input['last_name'] ?? null,
            'first_name_ar' => $input['first_name_ar'] ?? null,
            'last_name_ar' => $input['last_name_ar'] ?? null,
            'name' => $fullName ?: ($input['name'] ?? ''),
            'country_id' => $input['country_id'] ?? null,
            'wilaya_id' => $input['wilaya_id'] ?? null,
            'commune_id' => $input['commune_id'] ?? null,
            'city' => $input['city'] ?? null,
            'phone' => $input['phone'] ?? null,
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Self-registration is the author sign-up flow — admins are
        // manually provisioned (see the role-split notes), never created
        // here. Without this, a fresh registrant holds no role at all and
        // `role:author` middleware 403s them the moment `LoginResponse`
        // sends them to their dashboard. `findOrCreate` rather than a bare
        // role name: this action must not depend on RoleAndUserSeeder
        // having already run (e.g. a fresh test database).
        $user->assignRole(Role::findOrCreate('author'));

        return $user;
    }
}
