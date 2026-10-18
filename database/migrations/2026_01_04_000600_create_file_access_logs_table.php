<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vault's append-only, hash-chained legal ledger: every access to a
 * deposited file, the deposit event itself (RecordDeposit), and every
 * decision to release or keep vault bytes.
 *
 * No softDeletes (a deleted_at would let evidence be hidden) and no
 * updated_at (a row is written once and never modified).
 *
 * `media_file_id` has no delete action on purpose: a ledger row must block
 * the hard delete of the file it records, never vanish with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_access_logs', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('media_file_id')->constrained('media_files')->noActionOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // 'system' for queued work with no request context.
            $table->string('ip', 45);
            $table->string('user_agent')->nullable();
            $table->unsignedBigInteger('bytes_sent')->nullable();
            $table->string('range_header')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64);
            $table->enum('action', [
                'stream', 'download', 'preview', 'delete', 'purge', 'deposit', 'inspect',
                'admin_consult', 'bytes_released', 'bytes_kept', 'bytes_missing', 'consult_original',
            ]);
            $table->timestamp('created_at')->useCurrent();

            // A file's access history in order.
            $table->index(['media_file_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_access_logs');
    }
};
