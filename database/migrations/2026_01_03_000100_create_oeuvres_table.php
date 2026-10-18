<?php

declare(strict_types=1);

use App\Enums\Oeuvre\StatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The legal deposit record: one oeuvre, its classification and its review
 * lifecycle. `status` is only ever written by OeuvreStatusMachine.
 *
 * Classification keys are `restrictOnDelete`: an oeuvre is a legal deposit
 * record, so hard-deleting a reference row an oeuvre points at must fail
 * rather than cascade into, or silently null out, a filed classification.
 * Retiring a reference row is a soft delete, which leaves the key intact.
 * All four are nullable: the classification is chosen on the create page,
 * and Editeur / Artiste-interprète / Producteur have no type de gestion.
 *
 * `reviewed_by` is load-bearing, not decorative: it is the officer who
 * currently *holds* the deposit (set when it moves to `under_review`), and
 * the concurrency guard refuses a decision from anyone else unless they
 * explicitly take it over. `nullOnDelete`: losing which officer held a
 * deposit is acceptable — the durable record of who decided what is
 * `oeuvre_reviews.actor_id`, which restricts instead.
 *
 * `title` is nullable: the create page does not ask for it; the UI shows a
 * label derived from the college and creation date until it is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oeuvres', function (Blueprint $table) {
            $table->ondaKeys();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('register_type_id')->nullable()->constrained('register_types')->restrictOnDelete();
            $table->foreignId('type_gestion_id')->nullable()->constrained('type_gestions')->restrictOnDelete();
            $table->foreignId('register_type_college_id')->nullable()->constrained('register_type_colleges')->restrictOnDelete();
            $table->foreignId('register_type_member_id')->nullable()->constrained('register_type_members')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            // NOT redundant with register_type_college_id — do not remove as
            // duplication. A registered deposit records the classification it
            // was filed under. If a collège is renamed or re-coded in three
            // years, the deposit's record must not silently change with it.
            // The foreign key gives you joins; the snapshot gives you the
            // truth as of the filing date.
            $table->string('code_college_snapshot')->nullable();
            $table->unsignedTinyInteger('status')
                ->default(StatusEnum::DRAFT->value)
                ->comment(StatusEnum::dbComment());
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // The admin queue: a status filter ordered by submitted_at.
            $table->index(['status', 'submitted_at']);
            // An author's oeuvres by status (list filters, dashboard counts).
            $table->index(['author_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oeuvres');
    }
};
