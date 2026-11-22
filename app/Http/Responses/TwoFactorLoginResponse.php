<?php

namespace App\Http\Responses;

use App\Models\User;
use App\Services\Auth\LoginMethod;
use App\Services\Auth\LoginNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(private readonly LoginNotificationService $notifier) {}

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        $user = $request->user();

        // Built only after the code was accepted and the user logged in.
        // Fortify tries the recovery code first and, when it is valid, consumes
        // it (RecoveryCodeReplaced, listened to in FortifyServiceProvider). That
        // marker, not the mere presence of the field, says which proof was used.
        if ($user instanceof User) {
            $this->notifier->accepted(
                $user,
                $request->attributes->getBoolean(LoginMethod::RECOVERY_MARKER) ? LoginMethod::RecoveryCode : LoginMethod::TwoFactor,
            );
        }

        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->to(LoginResponse::redirectPath($request));
    }
}
