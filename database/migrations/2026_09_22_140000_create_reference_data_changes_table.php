<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed which reference-data field, when, from what, to what.
 *
 * Reference data decides what the law requires of a deposit: flipping
 * `is_required` on a document changes which files an author must attach,
 * and disabling a college changes what can be filed at all. A change with
 * no record of who made it is indistinguishable from a bug.
 *
 * Append-only, like `file_access_logs`: no `updated_at`, no soft deletes,
 * and the same `prev_hash`/`row_hash` chain (App\Domain\Deposit\Value\RowHash)
 * so an altered old row stops matching what the next row was hashed
 * against. Reused rather than reinvented — no package was added.
 *
 * Polymorphic on purpose: five reference tables share one trail, and an
 * officer reviewing "what changed last week" wants one list, not five.
 * `subject_uuid` is denormalised alongside the morph so the trail still
 * names its row after a hard delete somewhere upstream.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_data_changes', function (Blueprint $table) {
            $table->ondaKeys();
            // restrictOnDelete, not nullOnDelete: `users` is soft-deleted, so
            // an officer never actually disappears, and a hard delete that
            // would orphan a decision must fail loudly.
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->morphs('subject');
            $table->uuid('subject_uuid');
            // Unqualified column name as the UI shows it, e.g. `is_disabled`.
            $table->string('field', 64);
            // Text, not the column's own type: one trail spans booleans,
            // integers, strings and the JSON `extensions` list. Values are
            // stored as their canonical JSON encoding so `false` and `""`
            // stay distinguishable from null.
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_data_changes');
    }
};
