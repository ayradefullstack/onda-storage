<?php

declare(strict_types=1);

use App\Models\CollegeOeuvreFile;
use App\Support\FileFormats;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The MIME types a required-document slot accepts, derived from its
 * `extensions` through App\Support\FileFormats.
 *
 * DERIVED, NEVER EDITED. `CollegeOeuvreFile`'s `saving` hook recomputes it
 * from `extensions` on every save, so no code path can write one without
 * the other. The admin UI renders it read-only.
 *
 * Stored rather than computed on read because `VerifyContentType` runs in a
 * queue worker against a file whose slot may have been retired since the
 * upload started, and because a stored value can be indexed and audited.
 * Stored derived data drifts, so two things guard it: the artisan command
 * `referentiel:sync-mime-types` recomputes every row, and a test asserts
 * that all 69 rows match what the registry derives today.
 *
 * Nullable with no default: existing rows are backfilled below, and a new
 * row gets its value from the model hook before it is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('college_oeuvre_files', function (Blueprint $table) {
            $table->json('mime_types')->nullable()->after('extensions');
        });

        // Backfill through the model so the derivation is the same one the
        // saving hook uses — a second copy of it here would be a second
        // thing to keep in step.
        CollegeOeuvreFile::withTrashed()->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $row->mime_types = FileFormats::mimeTypesFor($row->extensions);
                $row->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('college_oeuvre_files', function (Blueprint $table) {
            $table->dropColumn('mime_types');
        });
    }
};
