<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ONE approved exception to this phase's frozen `database/migrations/**`
 * (explicitly authorized — see the phase report). `RecordDeposit` needs to
 * append a hash-chained audit row for the legal deposit event itself, and
 * `file_access_logs` already carries the `prev_hash`/`row_hash` columns that
 * mechanism needs — but its `action` enum (`stream|download|preview|delete
 * |purge`) has no value for "deposit recorded". Extending the enum reuses
 * the existing hash chain instead of standing up a second one in a new
 * table, at the cost of `file_access_logs` now covering one non-access
 * event alongside its access events.
 *
 * MySQL's `ENUM` has no native "add value" DDL, so this rebuilds the column
 * via `MODIFY COLUMN` — `Schema::table()` with `->change()` would require
 * doctrine/dbal's limited enum support; a raw statement is more direct here.
 */
return new class extends Migration
{
    private const OLD_VALUES = ['stream', 'download', 'preview', 'delete', 'purge'];

    private const NEW_VALUES = ['stream', 'download', 'preview', 'delete', 'purge', 'deposit'];

    public function up(): void
    {
        $this->setEnum(self::NEW_VALUES);
    }

    public function down(): void
    {
        $this->setEnum(self::OLD_VALUES);
    }

    /**
     * @param  list<string>  $values
     */
    private function setEnum(array $values): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $list = collect($values)->map(fn (string $value) => "'{$value}'")->implode(',');

        DB::statement("ALTER TABLE file_access_logs MODIFY COLUMN action ENUM({$list}) NOT NULL");
    }
};
