<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\Auth\LoginAlertNotification;
use App\Notifications\Auth\VerifyEmailAddress;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Tells a user their account was just signed in to.
 *
 * Called only from the points where a login is ADMITTED (see
 * EnforceLoginLockout, TwoFactorLoginResponse, PasskeyLoginResponse), never
 * from the Login event: that event also fires for the registration
 * auto-login, a provisional password check before a 2FA challenge, and
 * remember-cookie restoration.
 *
 * An alert must never fail or undo a login, so any error is reported and
 * swallowed.
 */
class LoginNotificationService
{
    public function accepted(User $user, LoginMethod $method): void
    {
        try {
            // Captured now: the worker may run much later and without the
            // request's locale.
            $user->notify(new LoginAlertNotification(
                $method,
                CarbonImmutable::now('UTC'),
                app()->getLocale(),
                $user->hasVerifiedEmail()
                    ? VerifyEmailAddress::bindingFor($user->getEmailForVerification())
                    : null,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
