<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * The password WAS verified, but the lease was lost (this request no longer
 * owns the per-scope mutex: it expired and another request may have taken it)
 * or admission could not be confirmed (the final lockout check failed on
 * storage), so it can neither be trusted to clear the state nor be allowed to
 * admit the login.
 * The caller undoes whatever authentication the request created and answers
 * with the generic "busy" response (fail closed).
 */
final class LoginLeaseLostException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The login lockout lease was lost during the attempt.');
    }
}
