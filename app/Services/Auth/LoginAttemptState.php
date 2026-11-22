<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Symfony\Component\HttpFoundation\Response;

/**
 * What the login lockout stage remembers about ONE request while the Fortify
 * pipeline runs, so that success evidence and the session can be judged as
 * belonging to this request only.
 */
final class LoginAttemptState
{
    /** The response the routing pipeline rendered for a rejected password. */
    public ?Response $response = null;

    /** @var array<string, mixed> challenge keys of an EARLIER request, set aside */
    public array $previous = [];

    /** This request entered the stage that can create provisional auth. */
    public bool $started = false;

    /** A user was already stored in the session when the attempt began. */
    public bool $wasAuthenticated = false;

    /** This request verified a password. */
    public bool $verified = false;
}
