<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Raised inside the lockout's verification closure when the login pipeline
 * ended in an error that is NOT a rejected password (a database error while
 * preparing the session, for example). Such an attempt proves nothing about
 * the password: it must neither count as a failure nor clear earlier ones.
 * Carries the response the pipeline already rendered so the caller can hand
 * it back untouched.
 */
final class LoginAttemptInconclusiveException extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('The login attempt ended without a password verdict.');
    }
}
