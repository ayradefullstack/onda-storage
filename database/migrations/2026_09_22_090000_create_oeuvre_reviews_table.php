<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only decision history of an oeuvre: one row per status
 * transition, recording who moved it, from what, to what and why.
 *
 * A table rather than columns on `oeuvres` because an oeuvre can be
 * rejected, resubmitted and reviewed again — the sequence is the useful
 * artifact, and an officer looking at a resubmission needs last round's
 * reason. Same shape and same reasoning as `file_access_logs`.
 *
 * No `updated_at` and no `softDeletes` (see CLAUDE.md's soft-delete
 * classification): a decision record that can be edited or removed is not
 * evidence. `actor_id` is `restrictOnDelete` rather than `nullOnDelete` —
 * `users` is soft-deleted, so a deleting actor never actually disappears,
 * and a hard delete that would orphan a decision must fail loudly instead
 * of quietly erasing who made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oeuvre_reviews', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('oeuvre_id')->constrained('oeuvres')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            // Mandatory for a rejection, enforced by the status machine —
            // nullable here because a submission and a review-open carry no
            // reason, and a CHECK constraint on a value the machine already
            // guards would only duplicate it across two dialects.
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['oeuvre_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oeuvre_reviews');
    }
};
