<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Exceptions;

/**
 * The requested move is not in the transition table at all, or the actor
 * is not the kind of user allowed to make it.
 *
 * Every attempt to leave `registered` lands here: that row of the table is
 * empty by design, not by omission.
 */
final class IllegalTransition extends DepositException
{
    public static function between(string $from, string $to): self
    {
        return new self("An oeuvre cannot move from \"{$from}\" to \"{$to}\".");
    }

    public static function terminal(string $from, string $to): self
    {
        return new self("This oeuvre is registered. A registered deposit is final and cannot move to \"{$to}\".");
    }

    public static function actor(string $from, string $to): self
    {
        return new self("You are not allowed to move an oeuvre from \"{$from}\" to \"{$to}\".");
    }
}
