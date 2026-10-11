<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No softDeletes: derivative bytes must go with the row.
        Schema::create('consultation_assets', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->string('kind', 24);
            // -1 would be awkward in a unique index with NULLs; a single
            // (kind) asset keeps page_index NULL and the unique below still
            // holds on the DBs we run (MySQL/SQLite allow repeated NULLs, so
            // `StoreAsset` also guards single assets by kind).
            $table->unsignedInteger('page_index')->nullable();
            $table->string('path');
            // Hex of the 8-byte random CTR nonce; never derived.
            $table->char('nonce', 16);
            $table->unsignedBigInteger('size_bytes');
            // True when `path` points at a file owned by a `media_variants`
            // row (poster / waveform): the bytes are shared, never deleted here.
            $table->boolean('is_shared_variant')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['media_file_id', 'kind', 'page_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_assets');
    }
};
