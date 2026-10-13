<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `consult_original`: an admin was issued the signed URL of a file delivered
 * `original_inline` (the decrypted original rendered by the browser). It is a
 * different event from `admin_consult` (a derivative), so the ledger itself
 * distinguishes original exposures; no side channel in another column.
 *
 * ADDITIVE like the earlier enum migrations: it reads the column's current
 * values and appends, so it never drops a value another migration added, in
 * either order. MySQL only; sqlite stores the column as plain text.
 */
return new class extends Migration
{
    private const ADDED = ['consult_original'];

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
