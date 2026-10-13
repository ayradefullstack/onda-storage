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
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name_ar')->nullable()->after('last_name');
            $table->string('last_name_ar')->nullable()->after('first_name_ar');
            $table->foreignId('country_id')->nullable()->after('password')->constrained('countries')->nullOnDelete();
            $table->foreignId('wilaya_id')->nullable()->after('country_id')->constrained('wilayas')->nullOnDelete();
            $table->foreignId('commune_id')->nullable()->after('wilaya_id')->constrained('communes')->nullOnDelete();
            $table->string('city')->nullable()->after('commune_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['country_id']);
            $table->dropForeign(['wilaya_id']);
            $table->dropForeign(['commune_id']);
            $table->dropColumn([
                'first_name_ar',
                'last_name_ar',
                'country_id',
                'wilaya_id',
                'commune_id',
                'city',
            ]);
        });
    }
};
