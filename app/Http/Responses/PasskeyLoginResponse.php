<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use App\Services\Auth\LoginMethod;
use App\Services\Auth\LoginNotificationService;
use Laravel\Passkeys\Http\Responses\PasskeyLoginResponse as BasePasskeyLoginResponse;

/**
 * The package's passkey login response, plus the "you signed in" alert. It
 * is only built after the assertion was verified and the user logged in.
 */
class PasskeyLoginResponse extends BasePasskeyLoginResponse
{
    public function __construct(private readonly LoginNotificationService $notifier) {}

    public function toResponse($request)
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->notifier->accepted($user, LoginMethod::Passkey);
        }

        return parent::toResponse($request);
    }
}
