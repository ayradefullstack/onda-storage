<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only audit trail: no softDeletes (a deleted_at would let evidence be hidden)
        // and no updated_at (a row is written once and never modified).
        Schema::create('file_access_logs', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('media_file_id')->constrained('media_files');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('action', ['stream', 'download', 'preview', 'delete', 'purge']);
            $table->string('ip', 45);
            $table->string('user_agent')->nullable();
            $table->unsignedBigInteger('bytes_sent')->nullable();
            $table->string('range_header')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['media_file_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_access_logs');
    }
};
