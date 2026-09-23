<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('work_id')->constrained('works')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('extension', 32);
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk', 32);
            $table->string('path');
            // Plain index, NOT unique — a soft-deleted row must not block re-upload of the same bytes.
            $table->string('sha256_plain', 64)->index();
            $table->text('dek_wrapped');
            $table->char('nonce', 16);
            $table->string('mac_path');
            $table->enum('status', [
                'uploading', 'assembling', 'scanning', 'processing', 'ready', 'failed', 'quarantined',
            ])->default('uploading');
            $table->unsignedInteger('duration_sec')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('ref_count')->default(1);
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['work_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
