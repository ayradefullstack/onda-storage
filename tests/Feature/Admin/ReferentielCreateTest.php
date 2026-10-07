<?php

declare(strict_types=1);

use App\Domain\Deposit\OeuvreStatus;
use App\Models\CollegeOeuvreFile;
use App\Models\Oeuvre;
use App\Models\ReferenceDataChange;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\TypeGestion;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Creating reference rows, and what stays frozen afterwards.
 *
 * Covers the four entities an admin can now add (type, gestion, college,
 * qualité) plus the document slot a college needs before it can be enabled.
 * Every rule here is asserted on a DIRECT request: the form hiding a field
 * proves nothing, a crafted request is what the server has to survive.
 */
beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
    $this->admin = User::factory()->withRole('admin')->create();
});

// --- helpers --------------------------------------------------------------

function refPost($test, string $route, array $payload)
{
    return $test->actingAs($test->admin)->post(route($route), $payload);
}

function refCreateType($test, string $name = 'Sculpteurs'): RegisterType
{
    refPost($test, 'admin.referentiel.types.store', ['name' => $name])->assertSessionHasNoErrors();

    return RegisterType::where('name', trim($name))->firstOrFail();
}

function refCreateGestion($test, RegisterType $type, string $name = 'Gestion libre'): TypeGestion
{
    refPost($test, 'admin.referentiel.gestions.store', ['register_type' => $type->uuid, 'name' => $name])
        ->assertSessionHasNoErrors();

    return TypeGestion::where('register_type_id', $type->id)->where('name', $name)->firstOrFail();
}

function refCreateCollege($test, RegisterType $type, ?TypeGestion $gestion = null, string $code = 'ATELIER', string $name = 'Atelier'): RegisterTypeCollege
{
    refPost($test, 'admin.referentiel.colleges.store', array_filter([
        'register_type' => $type->uuid,
        'type_gestion' => $gestion?->uuid,
        'code_college' => $code,
        'name' => $name,
    ]))->assertSessionHasNoErrors();

    return RegisterTypeCollege::where('code_college', $code)->firstOrFail();
}

function refCreateDocument($test, RegisterTypeCollege $college, string $key = 'piece_identite')
{
    return refPost($test, 'admin.referentiel.documents.store', [
        'college' => $college->uuid,
        'document_key' => $key,
        'title' => 'Pièce d’identité',
        'extensions' => ['pdf'],
    ]);
}

function refCreateMember($test, RegisterTypeCollege $college, string $name = 'Sculpteur'): RegisterTypeMember
{
    refPost($test, 'admin.referentiel.membres.store', ['college' => $college->uuid, 'name' => $name])
        ->assertSessionHasNoErrors();

    return RegisterTypeMember::where('register_type_college_id', $college->id)->where('name', $name)->firstOrFail();
}

/** A new type → college → document → member chain, the college enabled. */
function refReadyBranch($test, ?TypeGestion $withGestion = null, ?RegisterType $type = null): array
{
    $type ??= refCreateType($test);
    $college = refCreateCollege($test, $type, $withGestion);
    refCreateDocument($test, $college)->assertSessionHasNoErrors();
    $member = refCreateMember($test, $college);

    $test->actingAs($test->admin)
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => false])
        ->assertSessionHasNoErrors();

    return [$type, $college->fresh(), $member];
}

/** @return list<array<string, mixed>> the step 1 tree, as the page receives it */
function refTree($test): array
{
    $author = User::factory()->withRole('author')->create(['email_verified_at' => now()]);
    $types = [];

    $test->actingAs($author)->get(route('oeuvres.create'))->assertInertia(function (Assert $page) use (&$types) {
        $types = $page->toArray()['props']['classification']['types'];
    });

    return $types;
}

function refOeuvreUnder(RegisterTypeCollege $college, string $status): Oeuvre
{
    return Oeuvre::factory()->create([
        'author_id' => User::factory()->withRole('author')->create(),
        'status' => $status,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        'registered_at' => $status === OeuvreStatus::REGISTERED ? now() : null,
    ]);
}

// --- create: each entity -----------------------------------------------------

test('a declarant type can be created, with a generated, frozen slug', function () {
    refPost($this, 'admin.referentiel.types.store', [
        'name' => "  Sculpteurs d'art ", 'name_ar' => 'نحاتون', 'name_en' => 'Sculptors',
    ])->assertSessionHasNoErrors();

    $type = RegisterType::where('slug', 'sculpteurs-dart')->firstOrFail();

    expect($type->name)->toBe("Sculpteurs d'art")
        ->and($type->is_system)->toBeFalse()
        ->and($type->status)->toBe(1)
        ->and($type->registerTypeColleges()->count())->toBe(0);

    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.types.update', $type), ['slug' => 'hacked', 'name_en' => 'Carvers'])
        ->assertSessionHasNoErrors();

    expect($type->fresh())->slug->toBe('sculpteurs-dart')->name_en->toBe('Carvers');
});

test('a type de gestion can be created and takes the next free value', function () {
    $type = refCreateType($this);

    $first = refCreateGestion($this, $type, 'Première');
    $second = refCreateGestion($this, $type, 'Deuxième');

    expect([$first->type_gestion, $second->type_gestion])->toBe([1, 2])
        ->and($first->is_system)->toBeFalse()
        ->and($first->status)->toBe(1);

    refCreateGestion($this, $type, 'Troisième');

    refPost($this, 'admin.referentiel.gestions.store', ['register_type' => $type->uuid, 'name' => 'Quatrième'])
        ->assertSessionHasErrors(['register_type' => 'admin.referentiel.errors.gestionLimit']);
});

test('a college can be created, and is created disabled', function () {
    $type = refCreateType($this);

    $college = refCreateCollege($this, $type);

    expect($college->is_disabled)->toBeTrue()
        ->and($college->is_system)->toBeFalse()
        ->and($college->register_type_id)->toBe($type->id)
        ->and($college->type_gestion_id)->toBeNull()
        ->and($college->code_college)->toBe('ATELIER');

    // `is_disabled` is not something a request chooses at creation.
    refPost($this, 'admin.referentiel.colleges.store', [
        'register_type' => $type->uuid, 'code_college' => 'AUTRE', 'name' => 'Autre', 'is_disabled' => false,
    ])->assertSessionHasNoErrors();

    expect(RegisterTypeCollege::where('code_college', 'AUTRE')->value('is_disabled'))->toBeTrue();
});

test('a qualité can be created with an optional code and localised names', function () {
    $college = refCreateCollege($this, refCreateType($this));

    refPost($this, 'admin.referentiel.membres.store', [
        'college' => $college->uuid, 'name' => 'Sculpteur', 'name_ar' => 'نحات', 'code_qlt' => 'S1',
    ])->assertSessionHasNoErrors();

    $member = RegisterTypeMember::where('register_type_college_id', $college->id)->firstOrFail();

    expect($member)->name_ar->toBe('نحات')->code_qlt->toBe('S1')->is_system->toBeFalse()
        ->and($member->available_in_registration)->toBeTrue();

    // Without a code too.
    refCreateMember($this, $college, 'Apprenti');
});

test('a required document can be created, its MIME types derived and never accepted', function () {
    $college = refCreateCollege($this, refCreateType($this));

    refPost($this, 'admin.referentiel.documents.store', [
        'college' => $college->uuid, 'document_key' => 'piece_identite', 'title' => 'Pièce',
        'extensions' => ['pdf'], 'mime_types' => ['application/x-evil'],
    ])->assertSessionHasNoErrors();

    $document = CollegeOeuvreFile::where('document_key', 'piece_identite')->firstOrFail();

    expect($document->mime_types)->toContain('application/pdf')->not->toContain('application/x-evil');

    refPost($this, 'admin.referentiel.documents.store', [
        'college' => $college->uuid, 'document_key' => 'script', 'title' => 'x', 'extensions' => ['exe'],
    ])->assertSessionHasErrors('extensions.0');

    refCreateDocument($this, $college)->assertSessionHasErrors(['document_key' => 'admin.referentiel.errors.keyTaken']);
});

// --- create: duplicates ---------------------------------------------------------

test('a duplicate name within a parent is rejected: trimmed, case-insensitive, retired rows included', function () {
    $auteur = RegisterType::where('slug', 'auteur')->firstOrFail();
    $collective = TypeGestion::where('register_type_id', $auteur->id)->where('type_gestion', 1)->firstOrFail();

    // Seeded as " oeuvres musicales", with a real leading space.
    expect(RegisterTypeCollege::where('code_college', 'MUSIQUE')->value('name'))->toBe(' oeuvres musicales');

    foreach (['oeuvres musicales', '  OEUVRES MUSICALES '] as $name) {
        refPost($this, 'admin.referentiel.colleges.store', [
            'register_type' => $auteur->uuid, 'type_gestion' => $collective->uuid,
            'code_college' => 'NOUVEAU_'.strlen($name), 'name' => $name,
        ])->assertSessionHasErrors(['name' => 'admin.referentiel.errors.nameTaken']);
    }

    // The same name under another parent is fine.
    $other = refCreateType($this, 'Autres');
    refCreateCollege($this, $other, null, 'AUTRES_1', 'oeuvres musicales');

    // A retired row still owns its name.
    $type = refCreateType($this, 'Zeta');
    $type->delete();

    refPost($this, 'admin.referentiel.types.store', ['name' => ' zeta '])
        ->assertSessionHasErrors(['name' => 'admin.referentiel.errors.nameTaken']);
});

test('a duplicate code_college is rejected, soft-deleted rows included', function () {
    $type = refCreateType($this);
    $college = refCreateCollege($this, $type, null, 'UNIQUE_CODE', 'Premier');

    refPost($this, 'admin.referentiel.colleges.store', [
        'register_type' => $type->uuid, 'code_college' => 'UNIQUE_CODE', 'name' => 'Second',
    ])->assertSessionHasErrors(['code_college' => 'admin.referentiel.errors.codeTaken']);

    $college->delete();

    refPost($this, 'admin.referentiel.colleges.store', [
        'register_type' => $type->uuid, 'code_college' => 'UNIQUE_CODE', 'name' => 'Second',
    ])->assertSessionHasErrors(['code_college' => 'admin.referentiel.errors.codeTaken']);

    // The shape is enforced as well.
    refPost($this, 'admin.referentiel.colleges.store', [
        'register_type' => $type->uuid, 'code_college' => 'bad code!', 'name' => 'Troisième',
    ])->assertSessionHasErrors(['code_college' => 'admin.referentiel.errors.codeShape']);
});

test('a code_qlt is unique within its college, and a name unique within its college', function () {
    $college = refCreateCollege($this, refCreateType($this));
    $other = refCreateCollege($this, $college->registerType, null, 'ATELIER_2', 'Atelier deux');

    refPost($this, 'admin.referentiel.membres.store', ['college' => $college->uuid, 'name' => 'A', 'code_qlt' => 'Q1'])
        ->assertSessionHasNoErrors();

    refPost($this, 'admin.referentiel.membres.store', ['college' => $college->uuid, 'name' => 'B', 'code_qlt' => 'Q1'])
        ->assertSessionHasErrors(['code_qlt' => 'admin.referentiel.errors.codeTaken']);

    refPost($this, 'admin.referentiel.membres.store', ['college' => $other->uuid, 'name' => 'B', 'code_qlt' => 'Q1'])
        ->assertSessionHasNoErrors();

    refPost($this, 'admin.referentiel.membres.store', ['college' => $college->uuid, 'name' => ' a '])
        ->assertSessionHasErrors(['name' => 'admin.referentiel.errors.nameTaken']);
});

// --- the gestion rule (D5) --------------------------------------------------------

test('for the seeded data, the types that show a gestion level are exactly {Auteur}', function () {
    $withGestions = RegisterType::all()->filter->hasActiveGestions()->pluck('name')->all();
    $inStepOne = collect(refTree($this))->where('is_auteur', true)->pluck('name')->all();

    expect($withGestions)->toBe(['Auteur'])
        ->and($inStepOne)->toBe(['Auteur']);
});

test('a type without gestions shows three levels and rejects a posted gestion', function () {
    [$type, $college, $member] = refReadyBranch($this);

    $branch = collect(refTree($this))->firstWhere('name', $type->name);
    expect($branch['is_auteur'])->toBeFalse()->and($branch['gestions'])->toBe([]);

    $author = User::factory()->withRole('author')->create(['email_verified_at' => now()]);
    $auteurGestion = TypeGestion::first();

    $this->actingAs($author)->post(route('oeuvres.store'), [
        'register_type_id' => $type->id, 'type_gestion_id' => $auteurGestion->id,
        'register_type_college_id' => $college->id, 'register_type_member_id' => $member->id,
    ])->assertSessionHasErrors(['type_gestion_id' => 'oeuvres.classification.errors.gestionNotAllowed']);

    // A gestion posted on the admin form is rejected for the same reason.
    refPost($this, 'admin.referentiel.colleges.store', [
        'register_type' => $type->uuid, 'type_gestion' => $auteurGestion->uuid, 'code_college' => 'AUTRE', 'name' => 'Autre',
    ])->assertSessionHasErrors(['type_gestion' => 'admin.referentiel.errors.gestionNotAllowed']);

    $this->actingAs($author)->post(route('oeuvres.store'), [
        'register_type_id' => $type->id,
        'register_type_college_id' => $college->id, 'register_type_member_id' => $member->id,
    ])->assertSessionHasNoErrors();
});

test('a new type with one active gestion shows the gestion level and requires a gestion', function () {
    $type = refCreateType($this, 'Peintres');

    // Without a gestion the type has none yet: its colleges take none.
    $gestion = refCreateGestion($this, $type);

    // Now it has one, so a college without a gestion is refused…
    refPost($this, 'admin.referentiel.colleges.store', [
        'register_type' => $type->uuid, 'code_college' => 'GALERIE', 'name' => 'Galerie',
    ])->assertSessionHasErrors(['type_gestion' => 'admin.referentiel.errors.gestionRequired']);

    // …and one under it is accepted.
    [, $college, $member] = refReadyBranch($this, $gestion, $type);

    $branch = collect(refTree($this))->firstWhere('name', 'Peintres');

    expect($branch['is_auteur'])->toBeTrue()
        ->and($branch['gestions'])->toHaveCount(1)
        ->and($branch['colleges'])->toHaveCount(1)
        ->and($branch['colleges'][0]['type_gestion_id'])->toBe($gestion->id);

    $author = User::factory()->withRole('author')->create(['email_verified_at' => now()]);

    $this->actingAs($author)->post(route('oeuvres.store'), [
        'register_type_id' => $type->id,
        'register_type_college_id' => $college->id, 'register_type_member_id' => $member->id,
    ])->assertSessionHasErrors(['type_gestion_id' => 'oeuvres.classification.errors.gestionRequired']);

    $this->actingAs($author)->post(route('oeuvres.store'), [
        'register_type_id' => $type->id, 'type_gestion_id' => $gestion->id,
        'register_type_college_id' => $college->id, 'register_type_member_id' => $member->id,
    ])->assertSessionHasNoErrors();

    expect(Oeuvre::where('author_id', $author->id)->value('register_type_college_id'))->toBe($college->id);
});

test('retiring a type\'s only gestion collapses it to three levels', function () {
    $type = refCreateType($this, 'Graveurs');
    $gestion = refCreateGestion($this, $type);

    expect($type->hasActiveGestions())->toBeTrue();

    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.gestions.update', $gestion), ['status' => 0])
        ->assertSessionHasNoErrors();

    expect($type->fresh()->hasActiveGestions())->toBeFalse()
        ->and(collect(refTree($this))->firstWhere('name', 'Graveurs')['is_auteur'])->toBeFalse();
});

// --- a new college starts disabled and cannot be enabled empty (D4) ---------------------

test('enabling a college with no active documents is refused, naming the college', function () {
    $college = refCreateCollege($this, refCreateType($this));

    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => false])
        ->assertSessionHasErrors([
            'is_disabled' => 'admin.referentiel.errors.collegeNoDocuments',
            'college' => $college->uuid,
        ]);

    expect($college->fresh()->is_disabled)->toBeTrue();
});

test('enabling the college after adding one document succeeds', function () {
    $college = refCreateCollege($this, refCreateType($this));

    refCreateDocument($this, $college)->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => false])
        ->assertSessionHasNoErrors();

    expect($college->fresh()->is_disabled)->toBeFalse();
});

test('re-enabling by publishing is refused the same way, and a disable is never refused', function () {
    [, $college] = refReadyBranch($this);

    // Disabling and unpublishing are always allowed.
    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => true, 'status' => 0])
        ->assertSessionHasNoErrors();

    // A college whose only document is gone cannot come back.
    $college->collegeOeuvreFiles()->delete();

    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.colleges.update', $college), ['status' => 1, 'is_disabled' => false])
        ->assertSessionHasErrors('is_disabled');
});

test('the guard does not block editing a seeded college that already has documents', function () {
    $college = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();

    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => true])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => false])
        ->assertSessionHasNoErrors();
});

test('a college created and enabled is walkable end to end in step 1', function () {
    [$type, $college, $member] = refReadyBranch($this);

    $branch = collect(refTree($this))->firstWhere('name', $type->name);

    expect($branch['colleges'])->toHaveCount(1)
        ->and($branch['colleges'][0])->toMatchArray(['code_college' => 'ATELIER', 'is_disabled' => false])
        ->and($branch['colleges'][0]['members'])->toHaveCount(1);

    $author = User::factory()->withRole('author')->create(['email_verified_at' => now()]);

    $this->actingAs($author)->post(route('oeuvres.store'), [
        'register_type_id' => $type->id,
        'register_type_college_id' => $college->id, 'register_type_member_id' => $member->id,
    ])->assertSessionHasNoErrors();

    $oeuvre = Oeuvre::where('author_id', $author->id)->firstOrFail();

    expect($oeuvre->code_college_snapshot)->toBe('ATELIER');
});

// --- immutability ----------------------------------------------------------------

test('codes, slug and parent ids cannot be changed after creation, even by a direct request', function () {
    [$type, $college, $member] = refReadyBranch($this);
    $other = refCreateType($this, 'Autre type');
    $otherCollege = refCreateCollege($this, $other, null, 'AUTRE_COLLEGE', 'Autre collège');

    $this->actingAs($this->admin)->patch(route('admin.referentiel.colleges.update', $college), [
        'code_college' => 'HACKED', 'register_type_id' => $other->id, 'type_gestion_id' => 999, 'code_dv' => 'X', 'name_en' => 'ok',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->patch(route('admin.referentiel.membres.update', $member), [
        'code_qlt' => 'HACKED', 'register_type_college_id' => $otherCollege->id, 'name_en' => 'ok',
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->patch(route('admin.referentiel.types.update', $type), [
        'slug' => 'hacked', 'name_en' => 'ok',
    ])->assertSessionHasNoErrors();

    $document = $college->collegeOeuvreFiles()->firstOrFail();

    $this->actingAs($this->admin)->patch(route('admin.referentiel.documents.update', $document), [
        'document_key' => 'hacked', 'register_type_college_id' => $otherCollege->id, 'title_en' => 'ok',
    ])->assertSessionHasNoErrors();

    expect($college->fresh())->code_college->toBe('ATELIER')->register_type_id->toBe($type->id)->name_en->toBe('ok')
        ->and($member->fresh())->code_qlt->toBeNull()->register_type_college_id->toBe($college->id)
        ->and($type->fresh()->slug)->toBe('sculpteurs')
        ->and($document->fresh())->document_key->toBe('piece_identite')->register_type_college_id->toBe($college->id);
});

test('name is editable on an admin-created row and refused on a system row', function () {
    [$type, $college, $member] = refReadyBranch($this);

    $this->actingAs($this->admin)->patch(route('admin.referentiel.colleges.update', $college), ['name' => '  Atelier d’art '])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->patch(route('admin.referentiel.membres.update', $member), ['name' => 'Maître sculpteur'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->patch(route('admin.referentiel.types.update', $type), ['name' => 'Sculpteurs & co'])
        ->assertSessionHasNoErrors();

    expect($college->fresh()->name)->toBe('Atelier d’art')
        ->and($member->fresh()->name)->toBe('Maître sculpteur')
        ->and($type->fresh()->name)->toBe('Sculpteurs & co');

    $system = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();
    $systemType = RegisterType::where('slug', 'auteur')->firstOrFail();
    $systemMember = RegisterTypeMember::where('is_system', true)->firstOrFail();
    $systemGestion = TypeGestion::firstOrFail();

    foreach ([
        ['admin.referentiel.colleges.update', $system],
        ['admin.referentiel.types.update', $systemType],
        ['admin.referentiel.membres.update', $systemMember],
        ['admin.referentiel.gestions.update', $systemGestion],
    ] as [$route, $row]) {
        $before = $row->name;

        $this->actingAs($this->admin)->patch(route($route, $row), ['name' => 'Renommé'])
            ->assertSessionHasErrors(['name' => 'admin.referentiel.errors.systemName']);

        expect($row->fresh()->name)->toBe($before);
    }

    // And a rename cannot collide with a sibling.
    $second = refCreateCollege($this, $type, null, 'SECOND', 'Second');

    $this->actingAs($this->admin)->patch(route('admin.referentiel.colleges.update', $second), ['name' => ' ATELIER D’ART '])
        ->assertSessionHasErrors(['name' => 'admin.referentiel.errors.nameTaken']);
});

test('no route and no policy deletes a reference row', function () {
    $models = [RegisterType::class, TypeGestion::class, RegisterTypeCollege::class, RegisterTypeMember::class, CollegeOeuvreFile::class];

    foreach ($models as $model) {
        expect(Gate::getPolicyFor($model))->toBeNull();
    }

    $college = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();

    foreach ([
        "/admin/referentiel/colleges/{$college->uuid}",
        "/admin/referentiel/types/{$college->registerType->uuid}",
        '/admin/referentiel/colleges',
        '/admin/referentiel/documents',
    ] as $url) {
        $this->actingAs($this->admin)->delete($url)->assertStatus(405);
    }

    $deleting = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'referentiel') && in_array('DELETE', $route->methods(), true));

    expect($deleting)->toHaveCount(0);
});

test('only an admin can create, and a refused request creates nothing', function () {
    $author = User::factory()->withRole('author')->create(['email_verified_at' => now()]);

    foreach (['types' => ['name' => 'X'], 'gestions' => ['name' => 'X'], 'colleges' => ['name' => 'X'], 'membres' => ['name' => 'X'], 'documents' => ['title' => 'X']] as $tab => $payload) {
        $this->actingAs($author)->post(route("admin.referentiel.{$tab}.store"), $payload)->assertForbidden();
    }

    expect(RegisterType::where('name', 'X')->exists())->toBeFalse();
});

test('a guest cannot create either', function () {
    $this->post(route('admin.referentiel.types.store'), ['name' => 'X'])->assertRedirect();

    expect(RegisterType::where('name', 'X')->exists())->toBeFalse();
});

// --- status: guarded, and children / oeuvres untouched ----------------------------------------

test('disabling a type hides it from step 1 and changes no college row', function () {
    [$type] = refReadyBranch($this);
    $colleges = RegisterTypeCollege::where('register_type_id', $type->id)->get()->map->getAttributes()->all();

    expect(collect(refTree($this))->pluck('name'))->toContain($type->name);

    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.types.update', $type), ['status' => 0])
        ->assertSessionHasNoErrors();

    expect(collect(refTree($this))->pluck('name'))->not->toContain($type->name)
        ->and(RegisterTypeCollege::where('register_type_id', $type->id)->get()->map->getAttributes()->all())->toBe($colleges);
});

test('disabling a college does not change the oeuvres classified under it', function () {
    [, $college] = refReadyBranch($this);
    $draft = refOeuvreUnder($college, OeuvreStatus::DRAFT);
    $review = refOeuvreUnder($college, OeuvreStatus::UNDER_REVIEW);
    $before = [$draft->fresh()->getAttributes(), $review->fresh()->getAttributes()];

    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => true, 'status' => 0])
        ->assertSessionHasNoErrors();

    expect([$draft->fresh()->getAttributes(), $review->fresh()->getAttributes()])->toBe($before)
        ->and($draft->fresh()->register_type_college_id)->toBe($college->id)
        ->and($draft->fresh()->code_college_snapshot)->toBe('ATELIER');

    // Still viewable by its author and reviewable by an admin.
    $this->actingAs($draft->author)->get(route('oeuvres.show', $draft))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.oeuvres.show', $review))->assertOk();
});

test('a registered oeuvre is unaffected by any change to its classification', function () {
    [$type, $college, $member] = refReadyBranch($this);
    $registered = refOeuvreUnder($college, OeuvreStatus::REGISTERED);
    $registered->forceFill(['register_type_member_id' => $member->id])->save();
    $before = $registered->fresh()->getAttributes();

    $this->actingAs($this->admin)->patch(route('admin.referentiel.membres.update', $member), ['is_disabled' => true, 'status' => 0, 'available_in_registration' => false, 'name' => 'Autre nom']);
    $this->actingAs($this->admin)->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => true, 'status' => 0, 'name' => 'Autre collège']);
    $this->actingAs($this->admin)->patch(route('admin.referentiel.types.update', $type), ['is_disabled' => true, 'status' => 0, 'name' => 'Autre type']);

    expect($registered->fresh()->getAttributes())->toBe($before);
});

test('the index props carry the blast radius and never a sequential id', function () {
    [$type, $college, $member] = refReadyBranch($this);
    refOeuvreUnder($college, OeuvreStatus::DRAFT);
    refOeuvreUnder($college, OeuvreStatus::REGISTERED);

    $this->actingAs($this->admin)->get(route('admin.referentiel.types'))->assertInertia(fn (Assert $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->firstWhere('uuid', $type->uuid)['reachable_colleges_count'] === 1));

    $this->actingAs($this->admin)->get(route('admin.referentiel.colleges', ['type' => $type->uuid]))->assertInertia(fn (Assert $page) => $page
        ->has('rows.data', 1)
        ->where('rows.data.0.oeuvres_by_status', ['draft' => 1, 'registered' => 1])
        ->where('rows.data.0.is_system', false)
        ->where('filters.type', $type->uuid));

    $this->actingAs($this->admin)->get(route('admin.referentiel.membres', ['college' => $college->uuid]))->assertInertia(fn (Assert $page) => $page
        ->has('rows.data', 1)
        ->where('rows.data.0.oeuvres_count', 0));

    $noId = function (mixed $value, string $path = '') use (&$noId): void {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            expect($key)->not->toBe('id', "sequential id exposed at {$path}.{$key}");
            $noId($child, "{$path}.{$key}");
        }
    };

    foreach (['types', 'gestions', 'colleges', 'membres', 'documents'] as $tab) {
        $this->actingAs($this->admin)->get(route("admin.referentiel.{$tab}"))->assertInertia(function (Assert $page) use ($noId, $tab) {
            $props = $page->toArray()['props'];

            foreach (['rows', 'types', 'gestions', 'colleges', 'tabs'] as $key) {
                if (isset($props[$key])) {
                    $noId($props[$key], "{$tab}.{$key}");
                }
            }
        });
    }
});

// --- audit ------------------------------------------------------------------------------------

test('every create writes an audit row per field, naming the admin', function () {
    $type = refCreateType($this);
    $gestion = refCreateGestion($this, $type);
    $college = refCreateCollege($this, $type, $gestion);
    $member = refCreateMember($this, $college);
    refCreateDocument($this, $college)->assertSessionHasNoErrors();
    $document = $college->collegeOeuvreFiles()->firstOrFail();

    foreach ([$type, $gestion, $college, $member, $document] as $row) {
        $changes = ReferenceDataChange::where('subject_uuid', $row->uuid)->get();

        expect($changes)->not->toBeEmpty()
            ->and($changes->pluck('actor_id')->unique()->all())->toBe([$this->admin->id])
            ->and($changes->pluck('old_value')->filter()->all())->toBe([])
            ->and($changes->pluck('field'))->toContain($row instanceof CollegeOeuvreFile ? 'document_key' : 'name');
    }

    expect(ReferenceDataChange::where('subject_uuid', $college->uuid)->where('field', 'is_disabled')->value('new_value'))->toBe('true');
});

test('every update and status change writes an audit row naming the admin', function () {
    [$type, $college, $member] = refReadyBranch($this);
    ReferenceDataChange::query()->delete();

    $this->actingAs($this->admin)->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => true]);
    $this->actingAs($this->admin)->patch(route('admin.referentiel.membres.update', $member), ['name_en' => 'Sculptor']);
    $this->actingAs($this->admin)->patch(route('admin.referentiel.types.update', $type), ['status' => 0]);

    expect(ReferenceDataChange::where('subject_uuid', $college->uuid)->where('field', 'is_disabled')->exists())->toBeTrue()
        ->and(ReferenceDataChange::where('subject_uuid', $member->uuid)->where('field', 'name_en')->value('new_value'))->toBe('"Sculptor"')
        ->and(ReferenceDataChange::where('subject_uuid', $type->uuid)->where('field', 'status')->exists())->toBeTrue()
        ->and(ReferenceDataChange::pluck('actor_id')->unique()->all())->toBe([$this->admin->id]);
});

test('a refused change leaves no audit row and no half-applied row', function () {
    $college = refCreateCollege($this, refCreateType($this));
    $count = ReferenceDataChange::count();

    $this->actingAs($this->admin)
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => false, 'name_en' => 'Should not stick'])
        ->assertSessionHasErrors('is_disabled');

    expect(ReferenceDataChange::count())->toBe($count)
        ->and($college->fresh()->name_en)->toBeNull();
});

test('a change is visible in step 1 immediately: there is no tree cache to go stale', function () {
    [$type, $college] = refReadyBranch($this);

    expect(collect(refTree($this))->firstWhere('name', $type->name)['colleges'])->toHaveCount(1);

    $this->actingAs($this->admin)->patch(route('admin.referentiel.colleges.update', $college), ['name_ar' => 'ورشة', 'status' => 0]);

    expect(collect(refTree($this))->firstWhere('name', $type->name)['colleges'])->toHaveCount(0);

    $this->actingAs($this->admin)->patch(route('admin.referentiel.colleges.update', $college), ['status' => 1]);

    expect(collect(refTree($this))->firstWhere('name', $type->name)['colleges'])->toHaveCount(1);
});
