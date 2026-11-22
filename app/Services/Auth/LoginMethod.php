<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * How an accepted login was proven. A payload value only (notification data
 * and i18n key suffix), never a database column.
 */
enum LoginMethod: string
{
    case Password = 'password';
    case TwoFactor = 'two_factor';
    case RecoveryCode = 'recovery_code';
    case Passkey = 'passkey';

    /** Request attribute set when Fortify consumed a recovery code for this login. */
    public const RECOVERY_MARKER = 'onda.login.recovery_code_used';
}
