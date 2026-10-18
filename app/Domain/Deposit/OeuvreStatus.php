<?php

declare(strict_types=1);

namespace App\Domain\Deposit;

/**
 * `oeuvres.status` string constants. The column is the enum declared in
 * `2026_01_03_000100_create_oeuvres_table.php`; these are the only names the
 * application may write, for the same reason MediaFileStatus exists — a
 * bare `'under-review'` literal is a status nothing ever matches.
 *
 * "Pending", as the deposit brief and the UI call it, is SUBMITTED. There
 * is deliberately no separate enum value for it.
 */
final class OeuvreStatus
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const REGISTERED = 'registered';

    public const REJECTED = 'rejected';

    /**
     * The statuses in which the author still owns the oeuvre: files may be
     * added and the record edited. Everything else is frozen — enforced by
     * OeuvrePolicy::update() and by InitUpload's status guard, not by the
     * UI hiding a button.
     *
     * @var list<string>
     */
    public const AUTHOR_EDITABLE = [self::DRAFT, self::REJECTED];

    /**
     * A registered deposit never changes again. This is the property that
     * makes it evidence: a registration that can be revoked, amended or
     * re-decided is worth nothing in a dispute. OeuvreStatusMachine has no
     * outbound transition from it at all.
     */
    public static function isTerminal(string $status): bool
    {
        return $status === self::REGISTERED;
    }

    public static function isAuthorEditable(string $status): bool
    {
        return in_array($status, self::AUTHOR_EDITABLE, true);
    }
}
