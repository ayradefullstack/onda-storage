<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No softDeletes: an upload session is ephemeral scaffolding, not a legal record.
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
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
            $table->string('status', 32)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
