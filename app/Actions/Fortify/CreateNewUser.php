<?php

namespace App\Actions\Fortify;

use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Auth\RegistrationService;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    public function __construct(private readonly RegistrationService $registration) {}

    /**
     * Thin Fortify adapter. $input is ignored on purpose: RegisterRequest is
     * resolved lazily (after Fortify lowercased the email on the current
     * request), validates it, and only its validated() data is used.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $request = app(RegisterRequest::class);

        return $this->registration->register($request->validated());
    }
}
