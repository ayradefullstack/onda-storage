<?php

declare(strict_types=1);

use App\Models\CollegeOeuvreFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\RegisterTypeCollege;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * Step 2 on the oeuvre show page: one slot per required document of the
 * oeuvre's collège, and the completion count — which only a file at
 * `ready` moves.
 */
beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
});

function slotsPageAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function slotsPageOeuvre(User $author, string $codeCollege): Oeuvre
{
    $college = RegisterTypeCollege::where('code_college', $codeCollege)->firstOrFail();

    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
    ]);
}

function slotsPageRequirement(Oeuvre $oeuvre, string $documentKey): CollegeOeuvreFile
{
    return $oeuvre->requirements()->where('document_key', $documentKey)->firstOrFail();
}

/**
 * @param  list<string>  $keys
 */
function assertSlots(User $author, Oeuvre $oeuvre, array $keys): void
{
    test()->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('author/oeuvres/Show')
            ->has('requirements', count($keys))
            ->where('requirements', fn ($requirements): bool => collect($requirements)->pluck('document_key')->all() === $keys)
        );
}

// --- the slots ------------------------------------------------------------------

test('a MUSIQUE oeuvre renders exactly its 6 requirement slots, in order', function () {
    $author = slotsPageAuthor();
    $oeuvre = slotsPageOeuvre($author, 'MUSIQUE');

    assertSlots($author, $oeuvre, [
        'paroles', 'enregistrement_oeuvre', 'justificatif_exploitation',
        'autorisation_sample', 'autorisation_compositeur', 'file_editeur',
    ]);

    // The app's fallback locale is Arabic; ask for French explicitly.
    $this->withUnencryptedCookie('locale', 'fr')->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirements.0.title', 'Paroles')
            ->where('requirements.0.extensions', ['pdf'])
            ->where('requirements.0.is_required', true)
            ->where('requirements.2.conditions', null)
        );
});

test('a PRESTATION_AUDIOVISUELLE oeuvre renders its 6 slots', function () {
    $author = slotsPageAuthor();

    assertSlots($author, slotsPageOeuvre($author, 'PRESTATION_AUDIOVISUELLE'), [
        'declaration_enregistrement', 'contrat_travail_cession', 'attestation_diffusion_tv',
        'fiche_technique_videogramme', 'captures_ecran_prestation', 'autre',
    ]);
});

test('an EDITEUR_MUSICAL oeuvre renders its 4 slots', function () {
    $author = slotsPageAuthor();

    assertSlots($author, slotsPageOeuvre($author, 'EDITEUR_MUSICAL'), [
        'paroles_oeuvre', 'cd_commercialise', 'contrat_edition', 'justificatif_exploitation',
    ]);
});

test('slot titles follow the locale, falling back to the French title', function () {
    $author = slotsPageAuthor();
    $oeuvre = slotsPageOeuvre($author, 'PRODUCTION_PHONOGRAMME');

    $this->actingAs($author)->withUnencryptedCookie('locale', 'ar')
        ->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirements.0.title', 'تصريح التسجيل في الاستوديو')
            // No Arabic label in the source: falls back to French.
            ->where('requirements.1.title', 'Contrat de production')
        );
});

test('an unclassified oeuvre has no slots and an empty progress', function () {
    $author = slotsPageAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);

    $this->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirements', 0)
            ->where('progress', ['satisfied' => 0, 'total' => 0, 'conditional' => 0])
        );
});

test('an existing MediaFile with a null requirement id still loads and displays', function () {
    $author = slotsPageAuthor();
    $oeuvre = slotsPageOeuvre($author, 'MUSIQUE');
    $legacy = MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'college_oeuvre_file_id' => null,
        'document_key_snapshot' => null,
        'original_name' => 'avant-etape-2.mp4',
    ]);

    $this->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('mediaFiles', 1)
            ->where('mediaFiles.0.uuid', $legacy->uuid)
            ->where('mediaFiles.0.college_oeuvre_file_id', null)
            ->where('mediaFiles.0.original_name', 'avant-etape-2.mp4')
            // A ready file with no slot satisfies no requirement.
            ->where('progress.satisfied', 0)
        );
});

// --- completion -----------------------------------------------------------------

test('required-slot completion counts only files at ready', function () {
    $author = slotsPageAuthor();
    $oeuvre = slotsPageOeuvre($author, 'MUSIQUE');
    $justificatif = slotsPageRequirement($oeuvre, 'justificatif_exploitation');
    $paroles = slotsPageRequirement($oeuvre, 'paroles');

    $file = fn (CollegeOeuvreFile $requirement, string $status): MediaFile => MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'college_oeuvre_file_id' => $requirement->id,
        'document_key_snapshot' => $requirement->document_key,
        'status' => $status,
    ]);

    expect($oeuvre->requiredDocumentsProgress())->toBe(['satisfied' => 0, 'total' => 6, 'conditional' => 5]);

    $scanning = $file($justificatif, 'scanning');
    expect($oeuvre->requiredDocumentsProgress()['satisfied'])->toBe(0);

    $file($justificatif, 'processing');
    $file($justificatif, 'quarantined');
    $file($justificatif, 'failed');
    expect($oeuvre->requiredDocumentsProgress()['satisfied'])->toBe(0);

    $scanning->forceFill(['status' => 'ready'])->save();
    expect($oeuvre->requiredDocumentsProgress())->toBe(['satisfied' => 1, 'total' => 6, 'conditional' => 5]);

    // Two ready files in one slot still satisfy one requirement.
    $file($justificatif, 'ready');
    expect($oeuvre->requiredDocumentsProgress()['satisfied'])->toBe(1);

    // A conditional slot satisfied leaves one fewer "may not apply".
    $file($paroles, 'ready');
    expect($oeuvre->requiredDocumentsProgress())->toBe(['satisfied' => 2, 'total' => 6, 'conditional' => 4]);
});

test('a quarantined file alone does not satisfy its slot, and a soft-deleted ready file does not either', function () {
    $author = slotsPageAuthor();
    $oeuvre = slotsPageOeuvre($author, 'LOGICIEL');
    $codeSource = slotsPageRequirement($oeuvre, 'code_source');

    $attributes = [
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'college_oeuvre_file_id' => $codeSource->id,
        'document_key_snapshot' => 'code_source',
    ];

    MediaFile::factory()->create([...$attributes, 'status' => 'quarantined']);
    MediaFile::factory()->create([...$attributes, 'status' => 'ready'])->delete();

    // LOGICIEL: only code_source is required; schema_bdd and
    // oeuvre_format_numerique are optional and not counted.
    expect($oeuvre->requiredDocumentsProgress())->toBe(['satisfied' => 0, 'total' => 1, 'conditional' => 0]);

    $this->actingAs($author)->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn (Assert $page) => $page
            ->where('progress', ['satisfied' => 0, 'total' => 1, 'conditional' => 0])
        );
});
