<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renames `works` → `oeuvres` and every `work_id` foreign key →
 * `oeuvre_id` (`media_files`, `upload_sessions` — nothing else references
 * the table).
 *
 * A new migration rather than an edit to the `create_*_table` migrations,
 * so an already-migrated environment and a fresh install run the same
 * sequence and converge to the same schema.
 *
 * Constraints and indexes are dropped and recreated rather than carried
 * through the rename: MySQL's RENAME TABLE / RENAME COLUMN keep the old
 * names (`media_files_work_id_foreign`, `works_uuid_unique`), leaving the
 * schema full of names pointing at a table that no longer exists — and a
 * later `dropForeign(['oeuvre_id'])` would then fail to find its
 * constraint. On MySQL the implicit index backing a foreign key also
 * survives dropping the constraint, so it is dropped explicitly when
 * present; SQLite never creates one.
 *
 * Each step is its own `Schema::table()` call: on SQLite a foreign-key
 * change rebuilds the whole table, and keeping it apart from index and
 * column changes keeps each rebuild unambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename(from: 'work', to: 'oeuvre');
    }

    public function down(): void
    {
        $this->rename(from: 'oeuvre', to: 'work');
    }

    private function rename(string $from, string $to): void
    {
        $fromTable = "{$from}s";
        $toTable = "{$to}s";
        $fromKey = "{$from}_id";
        $toKey = "{$to}_id";

        // 1. Detach everything that names the old table or column.
        Schema::table('media_files', fn (Blueprint $table) => $table->dropForeign([$fromKey]));
        Schema::table('media_files', fn (Blueprint $table) => $table->dropIndex([$fromKey, 'status']));

        Schema::table('upload_sessions', fn (Blueprint $table) => $table->dropForeign([$fromKey]));
        $this->dropIndexIfPresent('upload_sessions', "upload_sessions_{$fromKey}_foreign");

        Schema::table($fromTable, fn (Blueprint $table) => $table->dropForeign(['author_id']));
        $this->dropIndexIfPresent($fromTable, "{$fromTable}_author_id_foreign");

        // 2. Rename.
        Schema::rename($fromTable, $toTable);
        Schema::table('media_files', fn (Blueprint $table) => $table->renameColumn($fromKey, $toKey));
        Schema::table('upload_sessions', fn (Blueprint $table) => $table->renameColumn($fromKey, $toKey));

        // 3. Reattach under the names a fresh `create` would have produced.
        Schema::table($toTable, fn (Blueprint $table) => $table->renameIndex("{$fromTable}_uuid_unique", "{$toTable}_uuid_unique"));
        Schema::table($toTable, fn (Blueprint $table) => $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete());

        // Index before constraint, so MySQL backs the FK with the composite
        // index (as the original create did) instead of adding its own.
        Schema::table('media_files', fn (Blueprint $table) => $table->index([$toKey, 'status']));
        Schema::table('media_files', fn (Blueprint $table) => $table->foreign($toKey)->references('id')->on($toTable)->cascadeOnDelete());

        Schema::table('upload_sessions', fn (Blueprint $table) => $table->foreign($toKey)->references('id')->on($toTable)->cascadeOnDelete());
    }

    private function dropIndexIfPresent(string $table, string $index): void
    {
        if (Schema::hasIndex($table, $index)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
        }
    }
};
