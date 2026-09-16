<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The classification an oeuvre is filed under, chosen on the create page.
 *
 * All nullable: every oeuvre created before this page has none. `title`
 * becomes nullable too — the create page no longer asks for it; a later step
 * in the deposit flow collects it, and the UI shows a label derived from the
 * college and creation date until then.
 *
 * Restrict on delete: an oeuvre is a legal deposit record, so hard-deleting a
 * reference row an oeuvre points at must fail rather than cascade into, or
 * silently null out, a filed classification. Retiring a reference row is a
 * soft delete, which leaves the key intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oeuvres', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
        });

        Schema::table('oeuvres', function (Blueprint $table) {
            $table->foreignId('register_type_id')->nullable()->after('description')
                ->constrained('register_types')->restrictOnDelete();
            // Null for Editeur, Artiste-interprète and Producteur, whose
            // colleges imply their gestion instead of offering a choice.
            $table->foreignId('type_gestion_id')->nullable()->after('register_type_id')
                ->constrained('type_gestions')->restrictOnDelete();
            $table->foreignId('register_type_college_id')->nullable()->after('type_gestion_id')
                ->constrained('register_type_colleges')->restrictOnDelete();
            $table->foreignId('register_type_member_id')->nullable()->after('register_type_college_id')
                ->constrained('register_type_members')->restrictOnDelete();

            // NOT redundant with register_type_college_id — do not remove as
            // duplication. A registered deposit records the classification it
            // was filed under. If a collège is renamed or re-coded in three
            // years, the deposit's record must not silently change with it.
            // The foreign key gives you joins; the snapshot gives you the
            // truth as of the filing date.
            $table->string('code_college_snapshot')->nullable()->after('register_type_member_id');
        });
    }

    public function down(): void
    {
        Schema::table('oeuvres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('register_type_member_id');
            $table->dropConstrainedForeignId('register_type_college_id');
            $table->dropConstrainedForeignId('type_gestion_id');
            $table->dropConstrainedForeignId('register_type_id');
            $table->dropColumn('code_college_snapshot');
        });

        // Untitled oeuvres exist once this migration has run; give them an
        // empty title so the column can become NOT NULL again.
        DB::table('oeuvres')->whereNull('title')->update(['title' => '']);

        Schema::table('oeuvres', function (Blueprint $table) {
            $table->string('title')->nullable(false)->change();
        });
    }
};
