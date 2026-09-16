<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Level 4 of the deposit classification: the qualité within a college.
 * Ported from ONDA's `register_type_members`, which has no name_ar/name_en
 * (ONDA dropped them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_type_members', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('register_type_college_id')->constrained('register_type_colleges')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            // Not unique: the same code means different things in different
            // colleges ("CO" is Co-Auteur in LOGICIEL, Conteur in
            // PRESTATION_AUDIOVISUELLE), and several members have none.
            $table->string('code_qlt')->nullable()->index();
            $table->unsignedTinyInteger('status')->default(1)->index();
            $table->boolean('is_disabled')->default(false);
            // False for ONDA's internal reference qualities, which are never
            // offered when declaring.
            $table->boolean('available_in_registration')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('register_type_members');
    }
};
