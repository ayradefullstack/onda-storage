<?php

declare(strict_types=1);

use App\Enums\MediaFile\ReviewStatusEnum;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\RegisterTypeCollege;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Spatie\Permission\Models\Role;

/**
 * GET admin/oeuvres/{oeuvre}/decision — the review dialog's one source of
 * truth. The dialog shows exactly the buttons in `allowed`, so every case
 * here is also checked against what the matching POST really does: a
 * summary that offers a decision the server then refuses (or hides one it
 * would accept) is the bug this endpoint exists to prevent.
 */
function summaryAdmin(string $name = 'Amina'): User
{
    Role::findOrCreate('admin');
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    $user->assignRole('admin');

    return $user;
}

function summaryAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

/** An unclassified deposit (no slots) with one file of the given status. */
function summaryOeuvre(OeuvreStatus $status, ?User $holder = null, MediaFileStatus $fileStatus = MediaFileStatus::READY): Oeuvre
{
    $author = summaryAuthor();

    $oeuvre = Oeuvre::factory()->create([
        'author_id' => $author->id,
        'title' => 'Nouba andalouse',
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        'reviewed_by' => $holder?->id,
        'reviewed_at' => $holder === null ? null : now(),
        'registered_at' => $status === OeuvreStatus::REGISTERED ? now() : null,
    ]);

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'original_name' => 'partition.pdf',
        'status' => $fileStatus,
        // Reviewed by an officer: registration needs every file accepted.
        'review_status' => $fileStatus === MediaFileStatus::READY ? ReviewStatusEnum::ACCEPTED : ReviewStatusEnum::PENDING,
    ]);

    return $oeuvre;
}

function summaryOf(User $admin, Oeuvre $oeuvre): array
{
    return test()->actingAs($admin)
        ->getJson(route('admin.oeuvres.decision', $oeuvre))
        ->assertOk()
        ->json();
}

test('a submitted deposit offers only "open_review", and opening it works', function () {
    $admin = summaryAdmin();
    $oeuvre = summaryOeuvre(OeuvreStatus::SUBMITTED);

    $summary = summaryOf($admin, $oeuvre);

    expect($summary['allowed'])->toBe(['open_review'])
        ->and($summary['blocked'])->toBe([])
        ->and($summary['holder'])->toBeNull()
        ->and($summary['heldByMe'])->toBeFalse()
        ->and($summary['status'])->toBe(OeuvreStatus::SUBMITTED->key())
        ->and($summary['reference'])->toBe($oeuvre->uuid)
        ->and($summary['title'])->toBe('Nouba andalouse')
        ->and($summary['author'])->toBe(['name' => $oeuvre->author->name])
        ->and($summary['submittedAt'])->not->toBeNull()
        ->and($summary['lastDecision'])->toBeNull();

    // Agreement: what was offered is what the server accepts — and a
    // decision that was not offered is refused.
    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertStatus(422);
    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $oeuvre))->assertOk();
});

test('under review and held by me: approve and reject, both of which the server accepts', function () {
    $admin = summaryAdmin();
    $oeuvre = summaryOeuvre(OeuvreStatus::UNDER_REVIEW, $admin);

    $summary = summaryOf($admin, $oeuvre);

    expect($summary['allowed'])->toBe(['approve', 'reject'])
        ->and($summary['blocked'])->toBe([])
        ->and($summary['heldByMe'])->toBeTrue()
        ->and($summary['holder'])->toBe(['id' => $admin->uuid, 'name' => 'Amina'])
        ->and($summary['checklist'])->toBe([
            'noFiles' => false,
            'requiredMissing' => [],
            'filesNotReady' => [],
            'conditionalMissing' => [],
            'filesNotAccepted' => [],
        ]);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertOk();
});

test('under review and held by another admin: only "take_over", which then opens approve and reject', function () {
    $holder = summaryAdmin('Karim');
    $admin = summaryAdmin();
    $oeuvre = summaryOeuvre(OeuvreStatus::UNDER_REVIEW, $holder);

    $summary = summaryOf($admin, $oeuvre);

    expect($summary['allowed'])->toBe(['take_over'])
        ->and($summary['heldByMe'])->toBeFalse()
        ->and($summary['holder'])->toBe(['id' => $holder->uuid, 'name' => 'Karim']);

    // Without the take-over, a decision is the 409 the dialog turns into
    // this same state.
    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))
        ->assertStatus(409)
        ->assertJsonPath('conflict.kind', 'held');

    $this->actingAs($admin)->postJson(route('admin.oeuvres.review', $oeuvre), ['take_over' => true])->assertOk();

    expect(summaryOf($admin, $oeuvre->refresh())['allowed'])->toBe(['approve', 'reject']);
});

test('a failing checklist moves approve to "blocked" and names every problem', function () {
    $admin = summaryAdmin();

    test()->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
    $college = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();

    $oeuvre = Oeuvre::factory()->create([
        'author_id' => summaryAuthor()->id,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
        'status' => OeuvreStatus::UNDER_REVIEW,
        'submitted_at' => now(),
        'reviewed_by' => $admin->id,
        'reviewed_at' => now(),
    ]);

    foreach (['enregistrement.wav' => MediaFileStatus::PROCESSING, 'pochette.png' => MediaFileStatus::QUARANTINED] as $name => $status) {
        MediaFile::factory()->create([
            'oeuvre_id' => $oeuvre->id,
            'uploaded_by' => $oeuvre->author_id,
            'original_name' => $name,
            'status' => $status,
        ]);
    }

    $required = $college->collegeOeuvreFiles()->where('document_key', 'justificatif_exploitation')->firstOrFail();

    $summary = summaryOf($admin, $oeuvre);

    expect($summary['allowed'])->toBe(['reject'])
        ->and($summary['blocked'])->toBe(['approve'])
        ->and($summary['checklist']['noFiles'])->toBeFalse()
        ->and($summary['checklist']['requiredMissing'])->toBe([trim($required->title_global)])
        ->and($summary['checklist']['filesNotReady'])->toBe([
            ['name' => 'enregistrement.wav', 'status' => MediaFileStatus::PROCESSING->key()],
            ['name' => 'pochette.png', 'status' => MediaFileStatus::QUARANTINED->key()],
        ])
        // The other five MUSIQUE documents are conditional: advisory only.
        ->and($summary['checklist']['conditionalMissing'])->toHaveCount(5);

    $this->actingAs($admin)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertStatus(422);
    $this->actingAs($admin)->postJson(route('admin.oeuvres.reject', $oeuvre), [
        'reason' => 'Le justificatif d\'exploitation manque, merci de le joindre.',
    ])->assertOk();
});

test('a deposit with no file at all blocks approve with "noFiles"', function () {
    $admin = summaryAdmin();
    $oeuvre = summaryOeuvre(OeuvreStatus::UNDER_REVIEW, $admin);
    $oeuvre->mediaFiles()->forceDelete();

    $summary = summaryOf($admin, $oeuvre);

    expect($summary['checklist']['noFiles'])->toBeTrue()
        ->and($summary['allowed'])->toBe(['reject'])
        ->and($summary['blocked'])->toBe(['approve']);
});

test('registered, rejected and draft deposits offer nothing', function (OeuvreStatus $status) {
    $admin = summaryAdmin();
    $oeuvre = summaryOeuvre($status, $status === OeuvreStatus::DRAFT || $status === OeuvreStatus::SUBMITTED ? null : $admin);

    $summary = summaryOf($admin, $oeuvre);

    expect($summary['allowed'])->toBe([])
        ->and($summary['blocked'])->toBe([])
        // `reviewed_by` names the decider once decided; it is not a holder.
        ->and($summary['holder'])->toBeNull()
        ->and($summary['heldByMe'])->toBeFalse();
})->with([OeuvreStatus::REGISTERED, OeuvreStatus::REJECTED, OeuvreStatus::DRAFT]);

test('lastDecision names who decided, what and when', function () {
    $other = summaryAdmin('Karim');
    $admin = summaryAdmin();
    $oeuvre = summaryOeuvre(OeuvreStatus::UNDER_REVIEW, $other);

    $this->actingAs($other)->postJson(route('admin.oeuvres.approve', $oeuvre))->assertOk();

    $summary = summaryOf($admin, $oeuvre->refresh());
    $row = OeuvreReview::where('oeuvre_id', $oeuvre->id)->where('to_status', OeuvreStatus::REGISTERED)->sole();

    expect($summary['allowed'])->toBe([])
        ->and($summary['status'])->toBe(OeuvreStatus::REGISTERED->key())
        ->and($summary['lastDecision'])->toBe([
            'to' => OeuvreStatus::REGISTERED->key(),
            'by' => 'Karim',
            'at' => $row->created_at->toIso8601String(),
        ]);
});

test('an author gets 403, a guest is sent to the login page', function () {
    $oeuvre = summaryOeuvre(OeuvreStatus::SUBMITTED);

    $this->actingAs($oeuvre->author)->getJson(route('admin.oeuvres.decision', $oeuvre))->assertForbidden();

    auth()->logout();
    $this->get(route('admin.oeuvres.decision', $oeuvre))->assertRedirect();
    $this->getJson(route('admin.oeuvres.decision', $oeuvre))->assertUnauthorized();
});

test('the summary is about the oeuvre in the URL and nothing else', function () {
    $admin = summaryAdmin();
    $mine = summaryOeuvre(OeuvreStatus::UNDER_REVIEW, $admin);
    $other = summaryOeuvre(OeuvreStatus::SUBMITTED, null, MediaFileStatus::PROCESSING);
    $other->forceFill(['title' => 'Chants du Hoggar'])->save();

    $response = $this->actingAs($admin)->getJson(route('admin.oeuvres.decision', $mine))->assertOk();

    expect($response->json('uuid'))->toBe($mine->uuid)
        ->and($response->json('title'))->toBe('Nouba andalouse')
        ->and($response->json('author.name'))->toBe($mine->author->name)
        ->and($response->getContent())
        ->not->toContain($other->uuid)
        ->not->toContain('Chants du Hoggar')
        ->not->toContain($other->author->name);

    $this->actingAs($admin)->getJson('/admin/oeuvres/'.fake()->uuid().'/decision')->assertNotFound();
});

test('the summary carries no sequential ids', function () {
    $admin = summaryAdmin();
    $oeuvre = summaryOeuvre(OeuvreStatus::UNDER_REVIEW, $admin);

    $summary = summaryOf($admin, $oeuvre);

    expect($summary)->not->toHaveKey('id')
        ->and($summary['holder']['id'])->toBe($admin->uuid);
});
