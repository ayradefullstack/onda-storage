<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\RegistrationUnavailableException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;

class RegistrationService
{
    /**
     * Create an author account from validated registration data.
     *
     * Does not dispatch Registered: the Fortify controller owns that, after commit.
     * The returned user is flagged as newly registered (see User::markNewlyRegistered).
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): User
    {
        $name = trim($this->text($data['first_name'] ?? null).' '.$this->text($data['last_name'] ?? null));

        if ($name === '') {
            $name = trim($this->text($data['first_name_ar'] ?? null).' '.$this->text($data['last_name_ar'] ?? null));
        }

        try {
            $user = DB::transaction(function () use ($data, $name): User {
                $user = User::create([
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'first_name_ar' => $data['first_name_ar'] ?? null,
                    'last_name_ar' => $data['last_name_ar'] ?? null,
                    'name' => $name,
                    'country_id' => $data['country_id'] ?? null,
                    'wilaya_id' => $data['wilaya_id'] ?? null,
                    'commune_id' => $data['commune_id'] ?? null,
                    'city' => $data['city'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null,
                    'password' => $data['password'] ?? null,
                ]);

                // Self-registration is the author flow; admins are provisioned manually.
                // The role is seeded (RoleSeeder), never created on the fly: a missing
                // role is a deployment fault, so roll the user back and say so.
                try {
                    $role = Role::findByName('author', 'web');
                } catch (RoleDoesNotExist) {
                    Log::critical('Registration failed: the author role is not seeded.', [
                        'role' => 'author',
                        'guard' => 'web',
                    ]);

                    throw new RegistrationUnavailableException;
                }

                $user->assignRole($role);

                return $user;
            });

            // After commit, on the very instance Fortify passes to Registered:
            // the first verification mail becomes the "account created" one.
            $user->markNewlyRegistered();

            return $user;
        } catch (UniqueConstraintViolationException $e) {
            // Rolled back: only an email collision maps to the email message;
            // any other unique violation is a real fault and is rethrown.
            if (User::withTrashed()->where('email', $data['email'] ?? null)->exists()) {
                throw ValidationException::withMessages([
                    'email' => __('validation.custom.email.unique'),
                ]);
            }

            throw $e;
        }
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
