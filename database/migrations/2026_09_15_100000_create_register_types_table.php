<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Level 1 of the deposit classification: the declarant type (Auteur,
 * Editeur, Artiste-interprète, Producteur). Ported from ONDA's
 * `register_types` in this project's conventions — `ondaKeys()` (UUIDv7
 * route key) instead of ONDA's `ulid`.
 *
 * Soft deletes: reference rows an admin may retire, which must stay
 * resolvable for anything already classified under them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_types', function (Blueprint $table) {
            $table->ondaKeys();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            // 1 = active — what ONDA's StatusEnum and scopeActive() treat as
            // active. ONDA's migration comment says "1 = Inactive, 2 = Active",
            // contradicting its own code; the code wins.
            $table->unsignedTinyInteger('status')->default(1)->index();
            $table->boolean('is_disabled')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('register_types');
    }
};
