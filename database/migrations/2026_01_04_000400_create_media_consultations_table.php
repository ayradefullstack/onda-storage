<?php

declare(strict_types=1);

use App\Enums\MediaConsultation\StatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultation state for one media file: the status of its derivative
 * pipeline. One row per file; its assets live in `consultation_assets`.
 * No softDeletes: a regenerable record (see CLAUDE.md's soft-delete table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_consultations', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('media_file_id')->unique()->constrained('media_files')->cascadeOnDelete();
            // String on purpose: a value of App\Infrastructure\Render\PreviewFamily
            // (string-backed), also used as a config key (vault.consult.delivery).
            $table->string('family', 24);
            $table->unsignedInteger('page_count')->nullable();
            // Short, user-facing reason key (e.g. "tool_missing:soffice"), never a stack trace.
            $table->string('reason', 191)->nullable();
            $table->unsignedTinyInteger('status')
                ->default(StatusEnum::PENDING->value)
                ->comment(StatusEnum::dbComment());
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_consultations');
    }
};
