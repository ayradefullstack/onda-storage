<?php

declare(strict_types=1);

use App\Models\Oeuvre;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\TypeGestion;
use App\Models\User;
use Database\Seeders\MembershipTypeSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(MembershipTypeSeeder::class);
});

function classifyingAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

function typeId(string $slug): int
{
    return RegisterType::where('slug', $slug)->value('id');
}

function gestionId(int $value): int
{
    return TypeGestion::where('register_type_id', typeId('auteur'))->where('type_gestion', $value)->value('id');
}

function collegeNamed(string $code): RegisterTypeCollege
{
    return RegisterTypeCollege::where('code_college', $code)->firstOrFail();
}

function memberOf(string $collegeCode, ?string $codeQlt = null): RegisterTypeMember
{
    $members = collegeNamed($collegeCode)->registerTypeMembers()->orderBy('id');

    return ($codeQlt === null ? $members : $members->where('code_qlt', $codeQlt))->firstOrFail();
}

/**
 * A coherent branch for a seeded college, as the page posts it: the gestion
 * key only for Auteur.
 *
 * @return array<string, int>
 */
function branchFor(string $collegeCode, ?string $codeQlt = null): array
{
    $college = collegeNamed($collegeCode);
    $payload = [
        'register_type_id' => $college->register_type_id,
        'register_type_college_id' => $college->id,
        'register_type_member_id' => memberOf($collegeCode, $codeQlt)->id,
    ];

    if ($college->type_gestion_id !== null) {
        $payload['type_gestion_id'] = $college->type_gestion_id;
    }

    return $payload;
}

/**
 * @param  array<string, mixed>  $page
 * @return array<string, mixed>
 */
function treeType(array $page, string $name): array
{
    return collect($page['props']['classification']['types'])->firstWhere('name', $name);
}

// --- the tree sent to the page ------------------------------------------------

test('the create page receives the whole filtered tree, with only the fields the UI needs', function () {
    $response = $this->actingAs(classifyingAuthor())->get(route('oeuvres.create'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('author/oeuvres/Create')
        ->has('classification.types', 4)
        ->has('classification.types.0', fn (Assert $type) => $type
            ->where('name', 'Auteur')
            ->where('is_auteur', true)
            ->has('id')->has('is_disabled')
            ->has('gestions', 3, fn (Assert $gestion) => $gestion->has('id')->has('name'))
            ->has('colleges', 14, fn (Assert $college) => $college
                ->hasAll(['id', 'name', 'code_college', 'type_gestion_id', 'is_disabled'])
                ->has('members', fn (Assert $members) => $members->each(
                    fn (Assert $member) => $member->hasAll(['id', 'name', 'available_in_registration']),
                )),
            ),
        ),
    );
});

test('REFERENTIEL_HORS_ADHESION never reaches the page, OEUVRE_FILM arrives disabled', function () {
    $page = $this->actingAs(classifyingAuthor())->get(route('oeuvres.create'))->viewData('page');

    $auteurColleges = collect(treeType($page, 'Auteur')['colleges']);
    $allCodes = collect($page['props']['classification']['types'])->flatMap(fn (array $type) => collect($type['colleges'])->pluck('code_college'));

    expect($allCodes)->not->toContain(RegisterTypeCollege::CODE_REFERENTIEL_HORS_ADHESION)
        ->and($allCodes)->toHaveCount(21)
        ->and($auteurColleges->firstWhere('code_college', 'OEUVRE_FILM')['is_disabled'])->toBeTrue();
});

test('Auteur colleges of gestion 1 are MUSIQUE, DRAMATIQUE, LITTERAIRE_EMISSION and OEUVRE_FILM', function () {
    $page = $this->actingAs(classifyingAuthor())->get(route('oeuvres.create'))->viewData('page');

    expect(collect(treeType($page, 'Auteur')['colleges'])->where('type_gestion_id', gestionId(1))->pluck('code_college')->values()->all())
        ->toBe(['MUSIQUE', 'DRAMATIQUE', 'LITTERAIRE_EMISSION', 'OEUVRE_FILM']);
});

test('Auteur colleges of gestion 3 are exactly the seven simple-protection colleges', function () {
    $page = $this->actingAs(classifyingAuthor())->get(route('oeuvres.create'))->viewData('page');

    expect(collect(treeType($page, 'Auteur')['colleges'])->where('type_gestion_id', gestionId(3))->pluck('code_college')->values()->all())
        ->toBe(['SIMPLE_LITTERAIRE_EDITION', 'SIMPLE_POESIE', 'SIMPLE_ARTS_GRAPHIQUES', 'LOGICIEL', 'RECHERCHE_SCIENTIFIQUE', 'SITE_WEB', 'THESES_MEMOIRES']);
});

test('types 2, 3 and 4 have no gestions, and type 3 yields exactly the four prestation colleges', function () {
    $page = $this->actingAs(classifyingAuthor())->get(route('oeuvres.create'))->viewData('page');

    foreach (['Editeur', 'Artiste-interprète', 'Producteur'] as $name) {
        expect(treeType($page, $name)['gestions'])->toBe([])
            ->and(treeType($page, $name)['is_auteur'])->toBeFalse();
    }

    expect(collect(treeType($page, 'Artiste-interprète')['colleges'])->pluck('code_college')->all())
        ->toBe(['PRESTATION_LYRIQUE', 'PRESTATION_DRAMATIQUE_CHOREGRAPHIQUE', 'PRESTATION_LITTERAIRE_EMISSION', 'PRESTATION_AUDIOVISUELLE']);
});

test('only members available for registration are sent', function () {
    RegisterTypeMember::create([
        'register_type_college_id' => collegeNamed('MUSIQUE')->id,
        'name' => 'Qualité interne',
        'code_qlt' => 'ZZ',
        'available_in_registration' => false,
    ]);

    $page = $this->actingAs(classifyingAuthor())->get(route('oeuvres.create'))->viewData('page');
    $musique = collect(treeType($page, 'Auteur')['colleges'])->firstWhere('code_college', 'MUSIQUE');

    expect(collect($musique['members'])->pluck('name')->all())
        ->toBe(['Auteur', 'Compositeur', 'Arrangeur', 'Adaptateur', 'Auteur-compositeur']);
});

test('names are localised and fall back to name when name_ar is null', function () {
    $page = $this->actingAs(classifyingAuthor())
        ->withUnencryptedCookie('locale', 'ar')
        ->get(route('oeuvres.create'))
        ->viewData('page');

    $auteur = treeType($page, 'Auteur');

    expect(collegeNamed('MUSIQUE')->name_ar)->toBeNull()
        ->and(collect($auteur['colleges'])->firstWhere('code_college', 'MUSIQUE')['name'])->toBe('oeuvres musicales')
        ->and(collect($auteur['gestions'])->pluck('name')->all())->toBe(['إدارة جماعية', 'إدارة فردية', 'حماية بسيطة']);
});

// --- persistence ----------------------------------------------------------------

test('a created oeuvre stores all five classification fields, including the snapshot', function () {
    $user = classifyingAuthor();

    $response = $this->actingAs($user)->post(route('oeuvres.store'), branchFor('MUSIQUE', 'C'));

    $oeuvre = Oeuvre::where('author_id', $user->id)->firstOrFail();

    expect($oeuvre->register_type_id)->toBe(typeId('auteur'))
        ->and($oeuvre->type_gestion_id)->toBe(gestionId(1))
        ->and($oeuvre->register_type_college_id)->toBe(collegeNamed('MUSIQUE')->id)
        ->and($oeuvre->register_type_member_id)->toBe(memberOf('MUSIQUE', 'C')->id)
        ->and($oeuvre->code_college_snapshot)->toBe('MUSIQUE')
        ->and($oeuvre->status)->toBe('draft')
        ->and($oeuvre->title)->toBeNull();

    $response->assertSessionHasNoErrors()->assertRedirect(route('oeuvres.show', $oeuvre));
});

test('a non-Auteur oeuvre stores no gestion', function () {
    $user = classifyingAuthor();

    $this->actingAs($user)->post(route('oeuvres.store'), branchFor('PRESTATION_AUDIOVISUELLE', '04'))->assertSessionHasNoErrors();

    $oeuvre = Oeuvre::where('author_id', $user->id)->firstOrFail();

    expect($oeuvre->type_gestion_id)->toBeNull()
        ->and($oeuvre->code_college_snapshot)->toBe('PRESTATION_AUDIOVISUELLE');
});

test('the oeuvre is always created for the authenticated author', function () {
    $user = classifyingAuthor();
    $other = classifyingAuthor();

    $this->actingAs($user)->post(route('oeuvres.store'), [...branchFor('LOGICIEL'), 'author_id' => $other->id]);

    expect(Oeuvre::firstOrFail()->author_id)->toBe($user->id);
});

test('the index and show pages label an untitled oeuvre by its college', function () {
    $user = classifyingAuthor();
    $this->actingAs($user)->post(route('oeuvres.store'), branchFor('LOGICIEL'));
    $oeuvre = Oeuvre::firstOrFail();

    $this->get(route('oeuvres.index'))->assertInertia(fn (Assert $page) => $page
        ->where('oeuvres.0.title', null)
        ->where('oeuvres.0.college_name', 'Logiciel'));

    $this->get(route('oeuvres.show', $oeuvre))->assertInertia(fn (Assert $page) => $page
        ->where('oeuvre.college_name', 'Logiciel')
        ->where('oeuvre.code_college_snapshot', 'LOGICIEL'));
});

// --- incoherent branches: each rejected on its own ---------------------------------

/**
 * @param  array<string, mixed>  $payload
 */
function rejects(array $payload, string $field, string $error): void
{
    test()->actingAs(classifyingAuthor())
        ->post(route('oeuvres.store'), $payload)
        ->assertSessionHasErrors([$field => "oeuvres.classification.errors.{$error}"]);

    expect(Oeuvre::count())->toBe(0);
}

test('a collège from the wrong type is rejected', function () {
    // Editeur, with a college of Artiste-interprète.
    rejects([
        ...branchFor('EDITEUR_MUSICAL'),
        'register_type_college_id' => collegeNamed('PRESTATION_LYRIQUE')->id,
        'register_type_member_id' => memberOf('PRESTATION_LYRIQUE', '03')->id,
    ], 'register_type_college_id', 'collegeWrongType');
});

test('a member from the wrong collège is rejected', function () {
    rejects([...branchFor('MUSIQUE'), 'register_type_member_id' => memberOf('DRAMATIQUE', 'SC')->id], 'register_type_member_id', 'memberWrongCollege');
});

test('a gestion that does not match the collège is rejected for Auteur', function () {
    rejects([...branchFor('MUSIQUE'), 'type_gestion_id' => gestionId(2)], 'register_type_college_id', 'collegeWrongGestion');
});

test('a gestion posted for type 3 is rejected', function () {
    rejects([...branchFor('PRESTATION_LYRIQUE', '03'), 'type_gestion_id' => gestionId(1)], 'type_gestion_id', 'gestionNotAllowed');
});

test('Auteur must send a gestion', function () {
    $payload = branchFor('MUSIQUE');
    unset($payload['type_gestion_id']);

    rejects($payload, 'type_gestion_id', 'gestionRequired');
});

test('a disabled collège is rejected', function () {
    rejects([
        'register_type_id' => typeId('auteur'),
        'type_gestion_id' => gestionId(1),
        'register_type_college_id' => collegeNamed('OEUVRE_FILM')->id,
        // OEUVRE_FILM has no members; a real member of its gestion is the most a crafted request can send.
        'register_type_member_id' => memberOf('MUSIQUE', 'A')->id,
    ], 'register_type_college_id', 'collegeUnavailable');
});

test('REFERENTIEL_HORS_ADHESION is rejected even when posted directly', function () {
    rejects([
        'register_type_id' => typeId('auteur'),
        'type_gestion_id' => gestionId(1),
        'register_type_college_id' => collegeNamed(RegisterTypeCollege::CODE_REFERENTIEL_HORS_ADHESION)->id,
        'register_type_member_id' => memberOf(RegisterTypeCollege::CODE_REFERENTIEL_HORS_ADHESION, 'IM')->id,
    ], 'register_type_college_id', 'collegeNotDepositable');
});

test('a member with available_in_registration = false is rejected', function () {
    $hidden = RegisterTypeMember::create([
        'register_type_college_id' => collegeNamed('MUSIQUE')->id,
        'name' => 'Qualité interne',
        'code_qlt' => 'ZZ',
        'available_in_registration' => false,
    ]);

    rejects([...branchFor('MUSIQUE'), 'register_type_member_id' => $hidden->id], 'register_type_member_id', 'memberUnavailable');
});

// --- the classification card on the upload page -------------------------------

test('the upload page shows the classification the oeuvre was filed under', function () {
    $this->actingAs(classifyingAuthor())->post(route('oeuvres.store'), branchFor('MUSIQUE', 'C'));
    $oeuvre = Oeuvre::firstOrFail();

    // French explicitly: with no cookie the app falls back to Arabic, and the
    // gestion labels are the only reference names that have Arabic text.
    $this->withUnencryptedCookie('locale', 'fr')->get(route('oeuvres.show', $oeuvre))->assertInertia(fn (Assert $page) => $page
        ->component('author/oeuvres/Show')
        ->where('classification', [
            'type' => 'Auteur',
            'gestion' => 'Gestion collective',
            'college' => 'oeuvres musicales',
            'code_college' => 'MUSIQUE',
            'member' => 'Compositeur',
            'code_qlt' => 'C',
        ]));
});

test('a non-Auteur classification card has no gestion', function () {
    $this->actingAs(classifyingAuthor())->post(route('oeuvres.store'), branchFor('PRODUCTION_PHONOGRAMME', '09'));
    $oeuvre = Oeuvre::firstOrFail();

    $this->get(route('oeuvres.show', $oeuvre))->assertInertia(fn (Assert $page) => $page
        ->where('classification.type', 'Producteur')
        ->where('classification.gestion', null)
        ->where('classification.code_college', 'PRODUCTION_PHONOGRAMME')
        ->where('classification.member', 'Producteur (trice)'));
});

test('the card keeps the filing-time code and a college retired after filing', function () {
    $this->actingAs(classifyingAuthor())->post(route('oeuvres.store'), branchFor('LOGICIEL', 'CO'));
    $oeuvre = Oeuvre::firstOrFail();

    $college = collegeNamed('LOGICIEL');
    $college->update(['code_college' => 'LOGICIEL_V2']);
    $college->delete();

    $this->withUnencryptedCookie('locale', 'fr')->get(route('oeuvres.show', $oeuvre))->assertInertia(fn (Assert $page) => $page
        ->where('classification.college', 'Logiciel')
        ->where('classification.code_college', 'LOGICIEL')
        ->where('classification.gestion', 'Simple protection'));
});

test('an oeuvre created before classification has no card', function () {
    $user = classifyingAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);

    $this->actingAs($user)->get(route('oeuvres.show', $oeuvre))->assertInertia(fn (Assert $page) => $page
        ->where('classification', null));
});

test('the card is localised, falling back to French where no translation exists', function () {
    $this->actingAs(classifyingAuthor())->post(route('oeuvres.store'), branchFor('MUSIQUE', 'C'));
    $oeuvre = Oeuvre::firstOrFail();

    $this->withUnencryptedCookie('locale', 'ar')->get(route('oeuvres.show', $oeuvre))->assertInertia(fn (Assert $page) => $page
        ->where('classification.gestion', 'إدارة جماعية')
        ->where('classification.college', 'oeuvres musicales'));
});
