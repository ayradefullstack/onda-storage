<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `upload_sessions`, `media_variants`, `file_access_logs`, and
 * `storage_quotas` each acquired a `deleted_at` column that contradicts
 * CLAUDE.md's schema conventions — SoftDeletes is reserved for `works`,
 * `media_files`, and `users` only:
 *
 * - `file_access_logs` is an append-only legal audit trail; a soft-deletable
 *   row would let an actor hide their own access.
 * - `storage_quotas.user_id` is unique; a soft-deleted quota row would
 *   permanently block creating a new quota for that user.
 * - `upload_sessions` is ephemeral scaffolding; a soft-deleted session would
 *   leave its `.part` file behind, corrupting cleanup and quota accounting.
 * - `media_variants` are regenerable derivatives that must be hard-deleted
 *   to actually free disk.
 *
 * A new migration (rather than editing the four `create_*_table` migrations
 * in place) so any environment that already ran `migrate` with the drifted
 * schema converges to the same corrected schema as a fresh `migrate:fresh`.
 */
return new class extends Migration
{
    private const TABLES = [
        'upload_sessions',
        'media_variants',
        'file_access_logs',
        'storage_quotas',
    ];

    /**
     * `hasColumn` guards make this idempotent across both convergence paths:
     * an environment that already ran the drifted migrations has the column
     * and this drops it; a fresh install's `create_*_table` migrations no
     * longer add `deleted_at` at all (P3 housekeeping), so there is nothing
     * to drop and this is a no-op.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->dropSoftDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $table): void {
                    $table->softDeletes();
                });
            }
        }
    }
};
