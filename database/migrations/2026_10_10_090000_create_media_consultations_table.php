<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No softDeletes: a regenerable derivative record, see CLAUDE.md's
        // soft-delete table. One row per media file; its assets live in
        // `consultation_assets`.
        Schema::create('media_consultations', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('media_file_id')->unique()->constrained('media_files')->cascadeOnDelete();
            $table->string('family', 24);
            $table->enum('status', ['pending', 'ready', 'failed', 'unsupported'])->default('pending');
            $table->unsignedInteger('page_count')->nullable();
            // Short, user-facing reason key (e.g. "tool_missing:soffice"), never a stack trace.
            $table->string('reason', 191)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_consultations');
    }
};
