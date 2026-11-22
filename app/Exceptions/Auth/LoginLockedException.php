<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * The (email + IP) scope is locked. Carries only the two instants a response
 * needs; nothing about the account or the attempt.
 */
final class LoginLockedException extends RuntimeException
{
    /**
     * @param  int  $lockedUntil  epoch seconds
     * @param  int  $serverTime  epoch seconds, the clock the decision was made on
     */
    public function __construct(
        public readonly int $lockedUntil,
        public readonly int $serverTime,
    ) {
        parent::__construct('Login is locked for this scope.');
    }
}
