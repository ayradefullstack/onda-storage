<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `media_files.sha256_plain` was NOT NULL from P1, but that was never
 * actually specified — computing it requires decrypting the full file, and
 * that work belongs in P5's async `ComputeContentHash` job, not inline in
 * the request that completes an upload (see CompleteUpload). A row is now
 * created with `sha256_plain = null` and `status = 'scanning'`; nothing may
 * read the hash before status reaches `ready`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->string('sha256_plain', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('media_files', function (Blueprint $table): void {
            $table->string('sha256_plain', 64)->nullable(false)->change();
        });
    }
};
