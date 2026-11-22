<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Registration cannot complete because the deployment is misconfigured (the
 * `author` role was not seeded). Thrown inside the registration transaction so
 * the user row is rolled back; the visitor sees a form-level error, never a
 * field error and never a stack trace.
 */
final class RegistrationUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Registration is unavailable: the author role does not exist.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth.registration_unavailable')], 503);
        }

        return back()
            ->withInput($request->except('password', 'password_confirmation'))
            ->withErrors(['registration' => __('auth.registration_unavailable')]);
    }
}
