<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `VariantEncryption::nonceFor()` derived a variant's nonce deterministically
 * from the file's own nonce plus the variant kind — safe across variants and
 * against the main file, but unsafe under `vault:reprocess`: regenerating a
 * variant reuses the same key and the same nonce over different plaintext
 * (a different ffmpeg build, a different frame), which is AES-256-CTR
 * keystream reuse. This column lets each variant get a genuinely random,
 * once-only nonce instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_variants', function (Blueprint $table) {
            $table->char('nonce', 16)->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('media_variants', function (Blueprint $table) {
            $table->dropColumn('nonce');
        });
    }
};
