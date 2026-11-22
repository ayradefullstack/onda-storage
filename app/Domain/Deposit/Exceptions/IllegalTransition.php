<?php

declare(strict_types=1);

namespace App\Domain\Deposit\Exceptions;

use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;

/**
 * The requested move is not in the transition table at all, or the actor
 * is not the kind of user allowed to make it.
 *
 * Every attempt to leave `registered` lands here: that row of the table is
 * empty by design, not by omission.
 */
final class IllegalTransition extends DepositException
{
    public static function between(OeuvreStatus $from, OeuvreStatus $to): self
    {
        return new self(__('deposit.illegal_between', ['from' => $from->label(), 'to' => $to->label()]));
    }

    public static function terminal(OeuvreStatus $from, OeuvreStatus $to): self
    {
        return new self(__('deposit.illegal_terminal', ['to' => $to->label()]));
    }

    public static function actor(OeuvreStatus $from, OeuvreStatus $to): self
    {
        return new self(__('deposit.illegal_actor', ['from' => $from->label(), 'to' => $to->label()]));
    }
}
