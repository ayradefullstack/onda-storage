<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->ondaKeys();
            $table->string('name');
            $table->string('native_name')->nullable();
            $table->string('arabic_name')->nullable();
            $table->string('nationality')->nullable();
            $table->string('nationality_ar')->nullable();
            $table->string('nationality_en')->nullable();
            $table->string('alpha2', 2)->unique();
            $table->string('alpha3', 3)->nullable();
            $table->string('numeric_code', 10)->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->string('phone_code', 20)->nullable();
            $table->string('flag_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
