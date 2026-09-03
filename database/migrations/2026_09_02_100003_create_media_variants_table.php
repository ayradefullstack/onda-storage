<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No softDeletes: variants are regenerable derivatives, not the record of deposit itself.
        Schema::create('media_variants', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('media_file_id')->constrained('media_files')->cascadeOnDelete();
            $table->enum('kind', ['poster', 'preview', 'waveform', 'thumbnail']);
            $table->string('path');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_variants');
    }
};
