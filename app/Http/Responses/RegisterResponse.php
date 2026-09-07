<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's own default implementation redirects to
 * `Fortify::redirects('register')`, which falls back to `config('fortify.home')`
 * — a static, role-blind value (see fortify.php's comment on `home`). Left
 * unbound, a freshly-registered author would land on `/` instead of their
 * dashboard the moment `home` is repointed to something role-neutral. This
 * mirrors LoginResponse/TwoFactorLoginResponse: the same role-aware
 * redirectPath() decides where a brand-new account (now always holding at
 * least `author`, see CreateNewUser) actually belongs.
 */
class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        return redirect()->intended(LoginResponse::redirectPath($request));
    }
}
