<?php

declare(strict_types=1);

use App\Enums\Referentiel\StatusEnum;
use App\Enums\TypeGestion\TypeGestionEnum;
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
 *
 * `status` decides whether a type has a gestion level at all (an active
 * gestion), so a gestion can be retired without deleting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('type_gestions', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('register_type_id')->constrained('register_types')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            // Same value as register_type_colleges.type_gestion.
            $table->unsignedTinyInteger('type_gestion')
                ->comment(TypeGestionEnum::dbComment());
            $table->unsignedTinyInteger('status')
                ->default(StatusEnum::ACTIVE->value)
                ->comment(StatusEnum::dbComment());
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // One label per gestion value per declarant type.
            $table->unique(['register_type_id', 'type_gestion']);
            // A declarant type's active gestions (create form, college admin counts).
            $table->index(['register_type_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_gestions');
    }
};
