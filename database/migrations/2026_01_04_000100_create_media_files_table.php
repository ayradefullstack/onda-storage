<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One deposited file: where its ciphertext lives in the vault, its wrapped
 * DEK and nonce, and its pipeline status.
 *
 * Soft delete does NOT free disk (see CLAUDE.md): a trashed row still owns
 * its bytes until `purged_at` is set.
 *
 * `college_oeuvre_file_id` — which required-document slot the file
 * satisfies; one file satisfies exactly one slot, so a foreign key, not a
 * pivot. Null on delete: a requirement row is only ever hard-deleted when
 * its collège is, and that must neither destroy a legal deposit (cascade)
 * nor block the delete (restrict) — `document_key_snapshot` keeps the
 * record. No foreign key can express that the requirement belongs to the
 * collège of the file's own oeuvre; InitUpload enforces that.
 *
 * `sha256_plain` is nullable: computing it requires decrypting the whole
 * file, which is the async ComputeContentHash job's work, not the request
 * that completes the upload. Nothing may read it before status is `ready`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('oeuvre_id')->constrained('oeuvres')->cascadeOnDelete();
            $table->foreignId('college_oeuvre_file_id')->nullable()->constrained('college_oeuvre_files')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            // NOT redundant with college_oeuvre_file_id — same reasoning as
            // oeuvres.code_college_snapshot. Requirement rows can be edited,
            // re-keyed or retired. A file deposited against
            // `justificatif_exploitation` must still say so in three years,
            // even if the requirement row has since changed. The foreign key
            // gives you joins; the snapshot gives you the truth as of the
            // upload.
            $table->string('document_key_snapshot')->nullable();
            $table->string('original_name');
            $table->string('extension', 32);
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('disk', 32);
            $table->string('path');
            // Plain index, NOT unique — a soft-deleted row must not block re-upload of the same bytes.
            $table->string('sha256_plain', 64)->nullable()->index();
            $table->text('dek_wrapped');
            $table->char('nonce', 16);
            $table->string('mac_path');
            $table->unsignedInteger('duration_sec')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('ref_count')->default(1);
            $table->enum('status', [
                'uploading', 'assembling', 'scanning', 'processing', 'ready', 'failed', 'quarantined',
            ])->default('uploading');
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // An oeuvre's files by status (submission gate, slot progress, removal gate).
            $table->index(['oeuvre_id', 'status']);
            // Files deposited against one requirement slot.
            $table->index('college_oeuvre_file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
