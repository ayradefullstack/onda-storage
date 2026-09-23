<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The review lifecycle timestamps on `oeuvres`. `registered_at` already
 * exists from the original create migration and is not re-added.
 *
 * `reviewed_by` is load-bearing, not decorative: it is the officer who
 * currently *holds* the deposit (set when it moves to `under_review`), and
 * the concurrency guard refuses a decision from anyone else unless they
 * explicitly take it over. See OeuvreStatusMachine.
 *
 * `nullOnDelete`: losing which officer held a deposit is acceptable — the
 * durable record of who decided what is `oeuvre_reviews.actor_id`, which
 * restricts instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oeuvres', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')
                ->constrained('users')->nullOnDelete();
        });

        // The admin queue orders by submitted_at within a status filter.
        Schema::table('oeuvres', function (Blueprint $table) {
            $table->index(['status', 'submitted_at']);
        });

        // Backfill. An oeuvre already past `draft` when this ran has no
        // submitted_at, and MySQL sorts NULL last on a DESC order — so
        // without this those deposits would sit permanently at the bottom
        // of the officers' queue, which is indistinguishable from being
        // lost. `updated_at` is the closest honest approximation of when
        // they moved.
        DB::table('oeuvres')
            ->whereNull('submitted_at')
            ->where('status', '!=', 'draft')
            ->update(['submitted_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('oeuvres', function (Blueprint $table) {
            $table->dropIndex(['status', 'submitted_at']);
        });

        Schema::table('oeuvres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['submitted_at', 'reviewed_at']);
        });
    }
};
