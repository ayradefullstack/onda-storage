<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * The per-scope mutex could not be taken (timeout, store error, unsupported
 * store). Fail closed: the password is NOT checked and nobody is
 * authenticated.
 */
final class LoginLockoutBusyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Login lockout state is busy.');
    }
}
