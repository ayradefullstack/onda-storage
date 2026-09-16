<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Labels for the "type de gestion" select, which only an Auteur sees. ONDA
 * hardcodes these as `TypeGestionEnum`; here they are rows so they can carry
 * their three translations like every other reference label.
 *
 * How colleges link to it:
 *
 * - `register_type_colleges.type_gestion_id` is a nullable foreign key to
 *   this table, set only for Auteur colleges. This table is seeded for
 *   Auteur only, matching the UI; every Editeur, Artiste-interprète and
 *   Producteur college keeps type_gestion_id = null rather than pointing at
 *   a row that belongs to Auteur.
 * - `register_type_colleges.type_gestion` (1 collective, 2 individual,
 *   3 simple protection) stays on every college, exactly as ONDA's dump has
 *   it — it is the value the seeder matches colleges on.
 *
 * `type_gestion` here is the same value, so a row can be resolved from a
 * college's tinyint without relying on this table's auto-increment id being
 * 1/2/3. Unique per register type, so one Auteur cannot hold two
 * "collective" labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('type_gestions', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('register_type_id')->constrained('register_types')->cascadeOnUpdate()->cascadeOnDelete();
            // Matches register_type_colleges.type_gestion: 1 collective, 2 individual, 3 simple.
            $table->unsignedTinyInteger('type_gestion');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['register_type_id', 'type_gestion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_gestions');
    }
};
