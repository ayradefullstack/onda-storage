<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard `notifications` table, backing the `database`
 * notification channel. The header bell reads it; nothing existed before
 * this migration (the popover was rendering hard-coded sample rows).
 *
 * Framework infrastructure, written and deleted by the framework's own
 * `DatabaseNotification` model — so no `ondaKeys()`, no soft deletes (see
 * CLAUDE.md: a "never" row for the same reason `sessions` is one).
 *
 * `data` holds an i18n key plus its parameters rather than a rendered
 * sentence, so the bell can render it in the reader's locale instead of
 * the actor's — see OeuvreSubmittedNotification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
