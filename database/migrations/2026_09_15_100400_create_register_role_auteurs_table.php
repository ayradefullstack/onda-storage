<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contributor roles available within a college. Ported from ONDA's
 * `register_role_auteurs`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_role_auteurs', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('register_type_college_id')->constrained('register_type_colleges')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->unsignedTinyInteger('status')->default(1)->index();
            $table->boolean('is_disabled')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('register_role_auteurs');
    }
};
