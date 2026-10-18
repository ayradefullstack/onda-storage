<?php

declare(strict_types=1);

namespace App\Enums\FileAccessLog;

use App\Enums\Concerns\EnumHelpers;
use App\Enums\Contracts\HasPresentation;

/**
 * `file_access_logs.action` — the vault ledger's event type, numbered in the
 * order the actions were added.
 *
 * HASH CHAIN: the row payload hashed into `row_hash` carries the action as
 * its KEY (`$action->key()`), which is exactly the string the column held
 * before it became an integer ('deposit', 'admin_consult', …). The key is
 * therefore part of the chain's format: never rename a case.
 */
enum ActionEnum: int implements HasPresentation
{
    use EnumHelpers;

    case STREAM = 1;
    case DOWNLOAD = 2;
    case PREVIEW = 3;
    case DELETE = 4;
    case PURGE = 5;
    case DEPOSIT = 6;
    case INSPECT = 7;
    case ADMIN_CONSULT = 8;
    case BYTES_RELEASED = 9;
    case BYTES_KEPT = 10;
    case BYTES_MISSING = 11;
    case CONSULT_ORIGINAL = 12;

    public function label(): string
    {
        return __('enums.file_access_log.action.'.$this->key());
    }

    public function color(): string
    {
        return match ($this) {
            self::DEPOSIT => 'success',
            self::DELETE, self::PURGE, self::BYTES_RELEASED => 'destructive',
            self::BYTES_MISSING => 'warning',
            self::INSPECT, self::ADMIN_CONSULT, self::CONSULT_ORIGINAL => 'primary',
            default => 'info',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::STREAM => 'play',
            self::DOWNLOAD => 'download',
            self::PREVIEW => 'eye',
            self::DELETE => 'trash-2',
            self::PURGE => 'flame',
            self::DEPOSIT => 'archive',
            self::INSPECT => 'search',
            self::ADMIN_CONSULT => 'scan-eye',
            self::BYTES_RELEASED => 'hard-drive-download',
            self::BYTES_KEPT => 'hard-drive',
            self::BYTES_MISSING => 'file-x',
            self::CONSULT_ORIGINAL => 'file-search',
        };
    }
}
