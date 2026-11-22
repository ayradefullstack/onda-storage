<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Failed-password state, one row per (normalized email + client IP) scope.
 *
 * Authoritative and independent of the cache store (a `cache:clear` must not
 * silently unlock anyone), which is why it is a table and not cache keys.
 * No raw email or IP is stored: `scope_hash` is an HMAC. Ephemeral, so no
 * soft deletes and no timestamps; rows are deleted on success, on expiry
 * (when next seen) and by the scheduled `model:prune`.
 *
 * `window_started_at` / `locked_until` are unix epoch SECONDS (UTC) in plain
 * integers on purpose: the lockout arithmetic is "seconds against now()", and
 * integers cannot be shifted by a session time zone, DST or sub-second
 * rounding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_lockouts', function (Blueprint $table) {
            $table->id();

            $table->char('scope_hash', 64)->unique();
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->unsignedBigInteger('window_started_at')->comment('epoch seconds of the first failure of the window');
            $table->unsignedBigInteger('locked_until')->nullable()->comment('epoch seconds; set once, when the threshold is reached');

            // Prunable: expired locks (locked_until <= now).
            $table->index('locked_until');
            // Prunable: expired failure windows that never locked.
            $table->index('window_started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_lockouts');
    }
};
