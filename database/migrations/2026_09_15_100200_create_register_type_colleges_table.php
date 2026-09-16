<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Level 3 of the deposit classification: the college within a declarant
 * type. Ported from ONDA's `register_type_colleges`.
 *
 * Not ported: ONDA's `ahdesion` — a misspelling in its create migration,
 * superseded by a later alter that added `adhesion`, and left behind. Only
 * `adhesion` is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_type_colleges', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('register_type_id')->constrained('register_types')->cascadeOnUpdate()->cascadeOnDelete();
            // The type de gestion row this college belongs to. Nullable:
            // type_gestions only holds Auteur's three labels, so Editeur,
            // Artiste-interprète and Producteur colleges have none. Null on
            // delete, not cascade — retiring a label must never delete the
            // colleges filed under it; `type_gestion` below still holds the
            // value.
            $table->foreignId('type_gestion_id')->nullable()->constrained('type_gestions')->cascadeOnUpdate()->nullOnDelete();
            // Load-bearing: the required-documents mapping keys off it.
            // Nullable because MembershipTypeSeeder clears every code before
            // re-assigning them (see there); unique implies the index.
            $table->string('code_college')->nullable()->unique();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->unsignedTinyInteger('status')->default(1)->index();
            // 1 collective, 2 individual, 3 simple protection — for every
            // college, including the ones with no type_gestion_id. Kept
            // alongside the foreign key: it is the value ONDA stores, and
            // the seeder matches colleges on it.
            $table->unsignedTinyInteger('type_gestion')->default(1)->index();
            // Droits voisins code: null for Auteur/Editeur colleges (droits
            // d'auteur), the college code for Artiste-interprète/Producteur.
            $table->string('code_dv')->nullable();
            $table->boolean('adhesion')->default(true);
            $table->boolean('is_disabled')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('register_type_colleges');
    }
};
