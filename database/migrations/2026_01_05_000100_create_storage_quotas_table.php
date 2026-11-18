<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One quota per user. `used_bytes` is the runtime counter charged in
 * CompleteUpload; it must reconcile to
 * `MediaFile::withTrashed()->whereNull('purged_at')->sum('size_bytes')`.
 * No softDeletes: `user_id` is unique, so a trashed row would block
 * creating a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_quotas', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('limit_bytes');
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_quotas');
    }
};
