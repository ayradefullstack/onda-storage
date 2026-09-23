<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The required-document definitions per college: which pieces a declaration
 * under a college must attach, in what order and in which formats. A
 * template, not uploads — the uploaded bytes are `media_files`.
 *
 * ONDA has no such table: the list is a PHP `match` on `code_college`
 * (docs/register/COLLEGE_DOCUMENTS_MATRIX.md §1.2). Seeded by
 * CollegeOeuvreFileSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('college_oeuvre_files', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('register_type_college_id')->constrained('register_type_colleges')->cascadeOnUpdate()->cascadeOnDelete();
            // The stable identifier (`paroles`, `autorisation_auteur`) — titles
            // are translated and re-worded, keys are not. Unique per college
            // only: the same key recurs across colleges.
            $table->string('document_key')->index();
            $table->string('title');
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            // Lowercase extension list, e.g. ["pdf","jpg","jpeg","png"] — the
            // server rule, from which the client `accept` is derived.
            $table->json('extensions');
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('display_order');
            $table->unsignedInteger('max_size_kb')->nullable();
            $table->boolean('allows_multiple')->default(true);
            // ONDA's show_when / hide_when / server condition, kept verbatim
            // and not yet read: the declaration fields they test do not
            // exist here yet.
            $table->json('conditions')->nullable();
            // True where `extensions` is a chosen default rather than a rule
            // ONDA enforced — for an officer to confirm.
            $table->boolean('needs_review')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Named explicitly: the generated name exceeds MySQL's 64 characters.
            $table->unique(['register_type_college_id', 'document_key'], 'college_oeuvre_files_college_document_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('college_oeuvre_files');
    }
};
