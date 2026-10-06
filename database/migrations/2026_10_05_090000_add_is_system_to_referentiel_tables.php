<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets admins CREATE reference rows without the seeders ever touching them.
 *
 * `is_system` marks the rows a seeder owns. The seeders set it on every row
 * they create or update; a row an admin creates keeps the default `false`.
 * That one boolean is what scopes the seeder's `code_college` reset and its
 * identity updates to its own rows (see MembershipTypeSeeder).
 *
 * BACKFILL: every row that exists when this migration runs came from a
 * seeder — there was no create path anywhere in the application before this
 * change — so all of them are marked `is_system = true`. Verified by
 * counting: after `up()`, `where is_system = false` is zero on all four
 * tables (asserted in ReferentielSeederOwnershipTest).
 *
 * Two columns are added because the create forms need them:
 * - `type_gestions.status` — "an active gestion" is what decides whether a
 *   type has a gestion level, and a gestion could not be retired before.
 *   Default 1, so every existing gestion stays active.
 * - `register_type_members.name_ar` / `name_en` — the other three entities
 *   already carry localised names; qualités were French-only.
 */
return new class extends Migration
{
    private const TABLES = [
        'register_types',
        'type_gestions',
        'register_type_colleges',
        'register_type_members',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->boolean('is_system')->default(false);
            });

            DB::table($table)->update(['is_system' => true]);
        }

        Schema::table('type_gestions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('status')->default(1);
            $table->index('status');
        });

        Schema::table('register_type_members', function (Blueprint $table): void {
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('register_type_members', function (Blueprint $table): void {
            $table->dropColumn(['name_ar', 'name_en']);
        });

        Schema::table('type_gestions', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('is_system');
            });
        }
    }
};
