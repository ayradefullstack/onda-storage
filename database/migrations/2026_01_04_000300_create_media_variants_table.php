<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted derivatives of a media file (poster, preview, waveform,
 * thumbnail). No softDeletes: variants are regenerable, and must be
 * hard-deleted so their bytes go with them.
 *
 * `nonce` is random and once-only per variant. Deriving it from the file's
 * own nonce plus the kind reused the keystream under `vault:reprocess`
 * (same key, same nonce, different plaintext) — AES-256-CTR must never do
 * that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_variants', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->string('path');
            $table->char('nonce', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->enum('kind', ['poster', 'preview', 'waveform', 'thumbnail']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_variants');
    }
};
