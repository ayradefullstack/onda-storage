<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

            $table->index(['is_active', 'sort_order']);
        });

        // The site is never language-less: a fresh database (and every test
        // run) starts with the three languages the portal ships bundles for.
        // Inserted here rather than in a seeder so `migrate` alone is enough.
        $now = now();

        DB::table('languages')->insert([
            ['name' => 'Arabic', 'code' => 'ar', 'native_name' => 'العربية', 'direction' => 'rtl', 'is_default' => true, 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'French', 'code' => 'fr', 'native_name' => 'Français', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'English', 'code' => 'en', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
