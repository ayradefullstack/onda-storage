<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An admin opening another author's `draft` is a privileged read of a work
 * the author may still change or delete, so it is written into the same
 * hash-chained ledger as every other access. None of the existing `action`
 * values describes it truthfully ('preview' means bytes were served), so
 * `inspect` is added — the same approach as 2026_09_05_090000 for `deposit`.
 */
return new class extends Migration
{
    private const OLD_VALUES = ['stream', 'download', 'preview', 'delete', 'purge', 'deposit'];

    private const NEW_VALUES = ['stream', 'download', 'preview', 'delete', 'purge', 'deposit', 'inspect'];

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
