<?php

declare(strict_types=1);

use App\Enums\UploadSession\StatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One chunked upload in flight. No softDeletes: an upload session is
 * ephemeral scaffolding, not a legal record — swept after expiry and
 * hard-deleted by CompleteUpload.
 *
 * `college_oeuvre_file_id` and `replaces_media_file_id` are carried from
 * InitUpload to CompleteUpload, which copies them onto the MediaFile row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('oeuvre_id')->constrained('oeuvres')->cascadeOnDelete();
            $table->foreignId('college_oeuvre_file_id')->nullable()->constrained('college_oeuvre_files')->cascadeOnUpdate()->nullOnDelete();
            // Set only on a replacement upload during review: the removed
            // rejected file this session reserves (InitUpload picks it,
            // CompleteUpload copies it onto media_files.replaces_media_file_id).
            // A live session (not finished, not expired) holds the reservation.
            $table->foreignId('replaces_media_file_id')->nullable()->constrained('media_files')->restrictOnDelete();
            $table->string('filename');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('received_chunks')->default(0);
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->binary('chunk_mask')->nullable();
            $table->text('dek_wrapped');
            $table->char('nonce', 16);
            $table->string('temp_path');
            $table->unsignedTinyInteger('status')
                ->default(StatusEnum::PENDING->value)
                ->comment(StatusEnum::dbComment());
            $table->timestamp('expires_at');
            $table->timestamps();

            // A user's live sessions (InitUpload's concurrency and quota checks).
            $table->index(['user_id', 'status']);
            // The expiry sweep.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
