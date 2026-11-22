<?php

declare(strict_types=1);

namespace App\Actions\Oeuvre;

use RuntimeException;

/**
 * A removal during review lost a race with an officer: the file is no
 * longer rejected (revised to accepted or pending), or the deposit is no
 * longer under review. A 409 with a machine code — the author did nothing
 * wrong, the page refreshes and shows the new state.
 */
final class RemovalConflict extends RuntimeException
{
    public const NO_LONGER_REJECTED = 'file_no_longer_rejected';

    public const OEUVRE_DECIDED = 'oeuvre_decided';

    /** A replacement the officer has just accepted: it is part of the record now. */
    public const NOT_REMOVABLE = 'file_not_removable';

    private function __construct(string $message, public readonly string $kind)
    {
        parent::__construct($message);
    }

    public static function noLongerRejected(): self
    {
        return new self(__('deposit.removal_no_longer_rejected'), self::NO_LONGER_REJECTED);
    }

    public static function notRemovable(): self
    {
        return new self(__('deposit.removal_not_removable'), self::NOT_REMOVABLE);
    }

    public static function oeuvreDecided(): self
    {
        return new self(__('deposit.removal_oeuvre_decided'), self::OEUVRE_DECIDED);
    }
}
