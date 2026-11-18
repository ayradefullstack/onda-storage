<?php

declare(strict_types=1);

use App\Enums\MediaFile\ReviewStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only decision history of one deposited file: one row per
 * change of `media_files.review_status` — who decided, from what, to what,
 * why, and whether they had consulted the file first.
 *
 * Same reasoning as `oeuvre_reviews`: the sequence is the evidence, so no
 * `softDeletes` (see CLAUDE.md's soft-delete classification) and the model
 * refuses updates and deletes. Both parent FKs are `restrictOnDelete`: a
 * hard delete of a file or oeuvre that has been decided on must fail
 * loudly rather than erase the decisions. `reviewer_id` is `nullOnDelete`
 * (`users` is soft-deleted, so in practice the officer never vanishes).
 *
 * `was_consulted` is audit evidence only, computed by DecideMediaFile from
 * `file_access_logs`; nothing reads it to allow or block a decision.
 *
 * Not part of any hash chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_file_reviews', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('media_file_id')->constrained('media_files')->restrictOnDelete();
            $table->foreignId('oeuvre_id')->constrained('oeuvres')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            // Required for a rejection (DecideMediaFile), null otherwise.
            $table->text('reason')->nullable();
            $table->unsignedTinyInteger('from_status')
                ->comment(ReviewStatusEnum::dbComment());
            $table->unsignedTinyInteger('to_status')
                ->comment(ReviewStatusEnum::dbComment());
            $table->boolean('was_consulted')->default(false);
            $table->timestamp('decided_at');
            $table->timestamps();

            // One file's decision history in order (the review timeline).
            $table->index(['media_file_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_file_reviews');
    }
};
