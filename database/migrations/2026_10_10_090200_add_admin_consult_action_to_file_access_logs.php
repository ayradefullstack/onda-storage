<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An admin consulting a deposit through derivatives is a privileged read of
 * someone else's work. None of the existing values says that truthfully
 * ('preview' predates it, 'inspect' means the page was opened), so
 * `admin_consult` is added.
 *
 * ADDITIVE on purpose: it reads the column's current values and appends, so
 * it never drops a value another migration added (the `bytes_*` migration
 * can be deployed before or after this one).
 */
return new class extends Migration
{
    private const ADDED = ['admin_consult'];

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
