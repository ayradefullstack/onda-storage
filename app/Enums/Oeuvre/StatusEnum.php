<?php

declare(strict_types=1);

namespace App\Enums\Oeuvre;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

/**
 * `oeuvres.status`, and `oeuvre_reviews.from_status` / `to_status`.
 * Written only by OeuvreStatusMachine.
 *
 * "Pending", as the deposit brief and the UI call it, is SUBMITTED. There
 * is deliberately no separate case for it.
 */
enum StatusEnum: int implements HasPresentation
{
    use EnumHelpers;

    case DRAFT = 1;
    case SUBMITTED = 2;
    case UNDER_REVIEW = 3;
    case REGISTERED = 4;
    case REJECTED = 5;

    public function label(): string
    {
        return __('enums.oeuvre.status.'.$this->key());
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'neutral',
            self::SUBMITTED => 'info',
            self::UNDER_REVIEW => 'warning',
            self::REGISTERED => 'success',
            self::REJECTED => 'destructive',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'file-pen',
            self::SUBMITTED => 'send',
            self::UNDER_REVIEW => 'search-check',
            self::REGISTERED => 'badge-check',
            self::REJECTED => 'circle-x',
        };
    }

    /**
     * The statuses in which the author still owns the oeuvre: files may be
     * added and the record edited. Everything else is frozen — enforced by
     * OeuvrePolicy::update() and by InitUpload's status guard, not by the
     * UI hiding a button.
     *
     * @return list<self>
     */
    public static function authorEditable(): array
    {
        return [self::DRAFT, self::REJECTED];
    }

    /**
     * Deposits still in flight — an author is filling one in, or an officer
     * is reading one. `registered` is finished and untouchable; `rejected`
     * waits on the author and is not counted.
     *
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::DRAFT, self::SUBMITTED, self::UNDER_REVIEW];
    }

    public function isAuthorEditable(): bool
    {
        return in_array($this, self::authorEditable(), true);
    }

    /**
     * A registered deposit never changes again. This is the property that
     * makes it evidence: a registration that can be revoked, amended or
     * re-decided is worth nothing in a dispute. OeuvreStatusMachine has no
     * outbound transition from it at all.
     */
    public function isTerminal(): bool
    {
        return $this === self::REGISTERED;
    }
}
