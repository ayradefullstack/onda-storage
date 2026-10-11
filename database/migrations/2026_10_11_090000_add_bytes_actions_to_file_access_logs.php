<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every decision to delete (or deliberately keep) vault bytes is written to
 * the same hash-chained ledger as every other access, and a deposit whose
 * bytes are found missing is recorded too.
 *
 * ADDITIVE on purpose: it reads the column's current values and appends, so
 * it can be deployed on its own (before the consultation work) and never
 * drops a value another migration added, in either order.
 */
return new class extends Migration
{
    private const ADDED = ['bytes_released', 'bytes_kept', 'bytes_missing'];

    public function up(): void
    {
        $this->setEnum(array_values(array_unique([...$this->current(), ...self::ADDED])));
    }

    public function down(): void
    {
        $this->setEnum(array_values(array_diff($this->current(), self::ADDED)));
    }

    /**
     * @return list<string>
     */
    private function current(): array
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return [];
        }

        $type = (string) (DB::selectOne("SHOW COLUMNS FROM file_access_logs LIKE 'action'")->Type ?? '');
        preg_match_all("/'([^']+)'/", $type, $matches);

        return $matches[1];
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
