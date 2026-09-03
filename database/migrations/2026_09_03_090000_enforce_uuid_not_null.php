<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `WithoutModelEvents` (and, equally, Model::withoutEvents(), saveQuietly(),
 * a bulk insert(), or a queued job that bypasses events) silently suppresses
 * HasUuidColumn's `creating` hook, producing a row with no uuid — which then
 * fails much later and far less clearly, typically as a broken
 * route-model-binding lookup.
 *
 * The application's default MySQL connection already runs in strict SQL
 * mode, so an INSERT omitting `uuid` already fails today. This migration
 * makes that guarantee an explicit property of the schema itself — not an
 * ambient connection setting that a future config change could quietly
 * relax — by re-declaring `uuid` NOT NULL with no default on every P1 table.
 */
return new class extends Migration
{
    private const TABLES = [
        'works',
        'media_files',
        'upload_sessions',
        'media_variants',
        'file_access_logs',
        'storage_quotas',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->uuid('uuid')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->uuid('uuid')->nullable(false)->change();
            });
        }
    }
};
