<?php

declare(strict_types=1);

use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\OeuvreStatus;
use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\RegisterTypeCollege;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * The submission gate, exercised through the real endpoint.
 *
 * MUSIQUE is the collège these tests use because it is the exact case the
 * conditions rule exists for: six required documents, of which exactly one
 * (`justificatif_exploitation`) carries no `conditions`. An author who used
 * no sample must still be able to submit — see SubmissionGate's docblock.
 */
beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
});

function gateAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function gateOeuvre(User $author, string $status = OeuvreStatus::DRAFT): Oeuvre
{
    $college = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();

    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
        'status' => $status,
    ]);
}

function gateSlot(string $documentKey): CollegeOeuvreFile
{
    return RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail()
        ->collegeOeuvreFiles()->where('document_key', $documentKey)->firstOrFail();
}

/**
 * A file in the slot that is the only unconditional requirement, so the
 * gate passes unless the test deliberately breaks something else.
 */
function gateSatisfyRequiredSlot(Oeuvre $oeuvre, string $status = MediaFileStatus::READY): MediaFile
{
    return MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $oeuvre->author_id,
        'college_oeuvre_file_id' => gateSlot('justificatif_exploitation')->id,
        'status' => $status,
    ]);
}

/**
 * The whole `submission` bag, not just its first message. Inertia's error
 * prop flattens each key to one string; the session bag keeps them all,
 * which is where "every reason at once" is actually observable.
 *
 * @return list<string>
 */
function gateBlockerMessages(): array
{
    return session('errors')?->get('submission') ?? [];
}

test('a draft with every unconditional requirement satisfied submits', function () {
    $author = gateAuthor();
    $oeuvre = gateOeuvre($author);
    gateSatisfyRequiredSlot($oeuvre);

    $this->actingAs($author)
        ->post(route('author.oeuvres.submit', $oeuvre))
        ->assertRedirect(route('oeuvres.show', $oeuvre));

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::SUBMITTED)
        ->and($oeuvre->fresh()->submitted_at)->not->toBeNull();
});

test('an empty conditional required slot does not block submission', function () {
    $author = gateAuthor();
    $oeuvre = gateOeuvre($author);
    gateSatisfyRequiredSlot($oeuvre);

    // Five of MUSIQUE's six required documents carry `conditions` and are
    // left empty on purpose. If any of them blocked, an author who used no
    // sample could never submit at all.
    $conditional = $oeuvre->requirements()->required()->get()
        ->filter(fn (CollegeOeuvreFile $r): bool => $r->conditions !== null);

    expect($conditional)->not->toBeEmpty();

    $this->actingAs($author)
        ->post(route('author.oeuvres.submit', $oeuvre))
        ->assertSessionHasNoErrors();

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::SUBMITTED);
});

test('an empty unconditional required slot blocks submission, naming the document', function () {
    $author = gateAuthor();
    $oeuvre = gateOeuvre($author);

    // A file that satisfies no slot at all, so "no files" is not the reason.
    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'college_oeuvre_file_id' => null,
        'status' => MediaFileStatus::READY,
    ]);

    $response = $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));

    $response->assertInvalid('submission');

    $slotTitle = trim(gateSlot('justificatif_exploitation')->title_global);
    expect(implode(' | ', gateBlockerMessages()))->toContain($slotTitle);

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::DRAFT);
});

test('a file still scanning blocks submission and is named', function () {
    $author = gateAuthor();
    $oeuvre = gateOeuvre($author);
    gateSatisfyRequiredSlot($oeuvre);

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'original_name' => 'still-scanning.mp4',
        'status' => MediaFileStatus::SCANNING,
    ]);

    $response = $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));

    $response->assertInvalid('submission');
    expect(implode(' | ', gateBlockerMessages()))->toContain('still-scanning.mp4');
    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::DRAFT);
});

test('a quarantined file blocks submission and is named', function () {
    $author = gateAuthor();
    $oeuvre = gateOeuvre($author);
    gateSatisfyRequiredSlot($oeuvre);

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'original_name' => 'infected.mp4',
        'status' => MediaFileStatus::QUARANTINED,
    ]);

    $response = $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));

    $response->assertInvalid('submission');
    expect(implode(' | ', gateBlockerMessages()))->toContain('infected.mp4');
});

test('a failed file blocks submission and is named', function () {
    $author = gateAuthor();
    $oeuvre = gateOeuvre($author);
    gateSatisfyRequiredSlot($oeuvre);

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'original_name' => 'broken.mp4',
        'status' => MediaFileStatus::FAILED,
    ]);

    $response = $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));

    $response->assertInvalid('submission');
    expect(implode(' | ', gateBlockerMessages()))->toContain('broken.mp4');
});

test('an oeuvre with no files at all is refused', function () {
    $author = gateAuthor();
    $oeuvre = gateOeuvre($author);

    $this->actingAs($author)
        ->post(route('author.oeuvres.submit', $oeuvre))
        ->assertInvalid('submission');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::DRAFT);
});

test('a refused submission returns every reason at once, not only the first', function () {
    $author = gateAuthor();
    $oeuvre = gateOeuvre($author);

    // Three independent problems: the unconditional slot is empty, one file
    // is mid-pipeline and another is quarantined.
    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'original_name' => 'still-processing.mp4',
        'college_oeuvre_file_id' => null,
        'status' => MediaFileStatus::PROCESSING,
    ]);
    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'original_name' => 'infected.mp4',
        'college_oeuvre_file_id' => null,
        'status' => MediaFileStatus::QUARANTINED,
    ]);

    $response = $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));

    $response->assertInvalid('submission');

    $messages = gateBlockerMessages();
    $joined = implode(' | ', $messages);

    expect($messages)->toHaveCount(3)
        ->and($joined)->toContain('still-processing.mp4')
        ->and($joined)->toContain('infected.mp4')
        ->and($joined)->toContain(trim(gateSlot('justificatif_exploitation')->title_global));
});

test('the show page carries the whole verdict, with conditional slots as advisories', function () {
    $author = gateAuthor();
    $oeuvre = gateOeuvre($author);
    gateSatisfyRequiredSlot($oeuvre);

    $this->actingAs($author)
        ->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('submission.can_submit', true)
            ->where('submission.is_open', true)
            ->where('submission.blockers', [])
            // The five conditional MUSIQUE documents, shown to the author as
            // "may not apply" and to the officer as their call to make.
            ->has('submission.advisories', 5)
        );
});

test('submitting someone else\'s oeuvre is 403', function () {
    $owner = gateAuthor();
    $stranger = gateAuthor();
    $oeuvre = gateOeuvre($owner);
    gateSatisfyRequiredSlot($oeuvre);

    $this->actingAs($stranger)
        ->post(route('author.oeuvres.submit', $oeuvre))
        ->assertStatus(Response::HTTP_FORBIDDEN);

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::DRAFT);
});

test('a successful submission writes exactly one review row', function () {
    $author = gateAuthor();
    $oeuvre = gateOeuvre($author);
    gateSatisfyRequiredSlot($oeuvre);

    $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));

    $reviews = OeuvreReview::where('oeuvre_id', $oeuvre->id)->get();

    expect($reviews)->toHaveCount(1)
        ->and($reviews->first()->from_status)->toBe(OeuvreStatus::DRAFT)
        ->and($reviews->first()->to_status)->toBe(OeuvreStatus::SUBMITTED)
        ->and($reviews->first()->actor_id)->toBe($author->id)
        ->and($reviews->first()->reason)->toBeNull();
});
