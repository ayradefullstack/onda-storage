<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single source of truth for which locales exist (LanguageService).
 * Keyed by `code`, not `uuid`: the code is the locale identifier used in
 * URLs and cookies. The shipped ar/fr/en rows come from LanguageSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 12)->unique();
            $table->string('native_name', 100);
            $table->string('direction', 3)->default('ltr');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // LanguageService::active(): active languages in display order.
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
