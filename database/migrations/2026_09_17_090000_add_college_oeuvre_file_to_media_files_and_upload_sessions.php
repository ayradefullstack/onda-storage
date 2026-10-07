<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which required-document slot (`college_oeuvre_files`) a deposited file
 * satisfies. One file satisfies exactly one slot: a foreign key, not a pivot.
 *
 * All nullable: every file deposited before per-requirement uploads has none,
 * and so does every file of an oeuvre filed before classification existed.
 *
 * Null on delete: a requirement row is only ever hard-deleted when its collège
 * is. That must neither destroy a legal deposit (cascade) nor block the delete
 * (restrict) — the snapshot below keeps the record. Retiring a requirement is
 * a soft delete, which leaves the key intact.
 *
 * No foreign key can express that the requirement belongs to the collège of
 * the file's own oeuvre; InitUpload enforces that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_files', function (Blueprint $table) {
            $table->foreignId('college_oeuvre_file_id')->nullable()->after('oeuvre_id')
                ->constrained('college_oeuvre_files')->cascadeOnUpdate()->nullOnDelete();
            $table->index('college_oeuvre_file_id');

            // NOT redundant with college_oeuvre_file_id — same reasoning as
            // oeuvres.code_college_snapshot. Requirement rows can be edited,
            // re-keyed or retired. A file deposited against
            // `justificatif_exploitation` must still say so in three years,
            // even if the requirement row has since changed. The foreign key
            // gives you joins; the snapshot gives you the truth as of the
            // upload.
            $table->string('document_key_snapshot')->nullable()->after('college_oeuvre_file_id');
        });

        // Carried from InitUpload to CompleteUpload, which copies it onto the
        // MediaFile row.
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->foreignId('college_oeuvre_file_id')->nullable()->after('oeuvre_id')
                ->constrained('college_oeuvre_files')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('college_oeuvre_file_id');
        });

        Schema::table('media_files', function (Blueprint $table) {
            $table->dropForeign(['college_oeuvre_file_id']);
            $table->dropIndex(['college_oeuvre_file_id']);
            $table->dropColumn(['college_oeuvre_file_id', 'document_key_snapshot']);
        });
    }
};
