<?php

declare(strict_types=1);

use App\Models\RegisterRoleAuteur;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\TypeGestion;
use Database\Seeders\MembershipTypeSeeder;

beforeEach(function () {
    $this->seed(MembershipTypeSeeder::class);
});

function auteur(): RegisterType
{
    return RegisterType::where('slug', 'auteur')->firstOrFail();
}

/**
 * @return list<array{0: string, 1: string|null, 2: bool}>
 */
function membersOf(string $code): array
{
    return RegisterTypeCollege::where('code_college', $code)->firstOrFail()
        ->registerTypeMembers()->orderBy('id')->get()
        ->map(fn (RegisterTypeMember $member): array => [$member->name, $member->code_qlt, $member->available_in_registration])
        ->all();
}

// --- counts: the ONDA dump's 4 / 21 / 100 / 87, plus OEUVRE_FILM -------------

test('it seeds exactly 4 types, 22 colleges, 100 members and 87 roles', function () {
    expect(RegisterType::count())->toBe(4)
        ->and(RegisterTypeCollege::count())->toBe(22)
        ->and(RegisterTypeMember::count())->toBe(100)
        ->and(RegisterRoleAuteur::count())->toBe(87);
});

test('running it twice changes no counts, codes or row contents', function () {
    $snapshot = fn (): array => [
        RegisterType::orderBy('id')->get()->map->only(['id', 'uuid', 'name', 'slug', 'status', 'is_disabled'])->all(),
        TypeGestion::orderBy('id')->get()->map->only(['id', 'uuid', 'register_type_id', 'type_gestion', 'name', 'name_ar', 'name_en'])->all(),
        RegisterTypeCollege::orderBy('id')->get()->map->only(['id', 'uuid', 'register_type_id', 'type_gestion_id', 'code_college', 'name', 'type_gestion', 'code_dv', 'is_disabled'])->all(),
        RegisterTypeMember::orderBy('id')->get()->map->only(['id', 'uuid', 'register_type_college_id', 'name', 'code_qlt', 'available_in_registration'])->all(),
        RegisterRoleAuteur::orderBy('id')->get()->map->only(['id', 'uuid', 'register_type_college_id', 'name'])->all(),
    ];

    $before = $snapshot();

    $this->seed(MembershipTypeSeeder::class);

    expect($snapshot())->toBe($before)
        ->and(RegisterTypeCollege::count())->toBe(22)
        ->and(RegisterTypeCollege::distinct()->count('code_college'))->toBe(22);
});

// --- college invariants -------------------------------------------------------

test('every college has a unique, non-null code_college', function () {
    expect(RegisterTypeCollege::whereNull('code_college')->count())->toBe(0)
        ->and(RegisterTypeCollege::distinct()->count('code_college'))->toBe(22);
});

test('type_gestion is always 1, 2 or 3', function () {
    expect(RegisterTypeCollege::pluck('type_gestion')->unique()->sort()->values()->all())
        ->toBe(TypeGestion::VALUES);
});

test('code_dv is null for Auteur and Editeur colleges and the college code for the other two types', function () {
    RegisterTypeCollege::with('registerType')->get()->each(function (RegisterTypeCollege $college) {
        in_array($college->registerType->slug, ['auteur', 'editeur'], true)
            ? expect($college->code_dv)->toBeNull()
            : expect($college->code_dv)->toBe($college->code_college);
    });

    expect(RegisterTypeCollege::whereNotNull('code_dv')->count())->toBe(6);
});

test('only REFERENTIEL_HORS_ADHESION and OEUVRE_FILM are disabled', function () {
    expect(RegisterTypeCollege::where('is_disabled', true)->orderBy('code_college')->pluck('code_college')->all())
        ->toBe([RegisterTypeCollege::CODE_OEUVRE_FILM, RegisterTypeCollege::CODE_REFERENTIEL_HORS_ADHESION])
        ->and(RegisterTypeCollege::where('is_disabled', false)->count())->toBe(20);
});

test('all six REFERENTIEL_HORS_ADHESION members are hidden from registration', function () {
    expect(membersOf(RegisterTypeCollege::CODE_REFERENTIEL_HORS_ADHESION))->toBe([
        ['Impresario', 'IM', false],
        ['Interprète', 'IN', false],
        ['Non définie', 'XX', false],
        ['Radio', '10', false],
        ['Télévision', '11', false],
        ['Exception', '21', false],
    ])->and(RegisterTypeMember::where('available_in_registration', false)->count())->toBe(6);
});

test('college names keep their leading spaces verbatim', function () {
    expect(RegisterTypeCollege::where('code_college', 'MUSIQUE')->value('name'))->toBe(' oeuvres musicales')
        ->and(RegisterTypeCollege::where('code_college', 'PRESTATION_AUDIOVISUELLE')->value('name'))->toBe('  Artiste de prestations audiovisuelles');
});

// --- the gestion cascade -----------------------------------------------------

test('Auteur with type_gestion 1 yields MUSIQUE, DRAMATIQUE, LITTERAIRE_EMISSION, REFERENTIEL_HORS_ADHESION and OEUVRE_FILM', function () {
    expect(auteur()->registerTypeColleges()->forGestion(TypeGestion::COLLECTIVE)->orderBy('id')->pluck('code_college')->all())
        ->toBe(['MUSIQUE', 'DRAMATIQUE', 'LITTERAIRE_EMISSION', 'REFERENTIEL_HORS_ADHESION', 'OEUVRE_FILM']);
});

test('Auteur with type_gestion 3 yields exactly the seven deferred colleges', function () {
    expect(auteur()->registerTypeColleges()->forGestion(TypeGestion::SIMPLE)->orderBy('id')->pluck('code_college')->all())
        ->toBe(['SIMPLE_LITTERAIRE_EDITION', 'SIMPLE_POESIE', 'SIMPLE_ARTS_GRAPHIQUES', 'LOGICIEL', 'RECHERCHE_SCIENTIFIQUE', 'SITE_WEB', 'THESES_MEMOIRES']);
});

test('availableForRegistration drops the two reference-only colleges and nothing else', function () {
    expect(auteur()->registerTypeColleges()->forGestion(TypeGestion::COLLECTIVE)->availableForRegistration()->orderBy('id')->pluck('code_college')->all())
        ->toBe(['MUSIQUE', 'DRAMATIQUE', 'LITTERAIRE_EMISSION'])
        ->and(RegisterTypeCollege::availableForRegistration()->count())->toBe(20);
});

test('availableInRegistration keeps every member except the six internal qualities', function () {
    expect(RegisterTypeMember::availableInRegistration()->count())->toBe(94);
});

test('type_gestions has the three Auteur labels and nothing for the other types', function () {
    expect(TypeGestion::count())->toBe(3)
        ->and(auteur()->typeGestions()->orderBy('type_gestion')->get()->map->only(['type_gestion', 'name', 'name_en', 'name_ar'])->all())
        ->toBe([
            ['type_gestion' => 1, 'name' => 'Gestion collective', 'name_en' => 'Collective management', 'name_ar' => 'إدارة جماعية'],
            ['type_gestion' => 2, 'name' => 'Gestion individuelle', 'name_en' => 'Individual management', 'name_ar' => 'إدارة فردية'],
            ['type_gestion' => 3, 'name' => 'Simple protection', 'name_en' => 'Simple protection', 'name_ar' => 'حماية بسيطة'],
        ]);
});

// --- type_gestion_id ----------------------------------------------------------

test('every Auteur college links to the type_gestions row for its type_gestion', function () {
    $colleges = auteur()->registerTypeColleges()->with('typeGestion')->get();

    expect($colleges)->toHaveCount(15);

    $colleges->each(function (RegisterTypeCollege $college) {
        expect($college->typeGestion)->not->toBeNull()
            ->and($college->typeGestion?->register_type_id)->toBe($college->register_type_id)
            ->and($college->typeGestion?->type_gestion)->toBe($college->type_gestion);
    });
});

test('colleges of the other declarant types have no type_gestion_id', function () {
    expect(RegisterTypeCollege::whereNotNull('type_gestion_id')->count())->toBe(15)
        ->and(RegisterTypeCollege::where('register_type_id', '!=', auteur()->id)->whereNotNull('type_gestion_id')->exists())->toBeFalse();
});

test('a type_gestions row reaches its colleges through the relation', function () {
    $counts = auteur()->typeGestions()->withCount('registerTypeColleges')->orderBy('type_gestion')
        ->pluck('register_type_colleges_count', 'type_gestion')->all();

    expect($counts)->toBe([1 => 5, 2 => 3, 3 => 7]);

    $simple = auteur()->typeGestions()->where('type_gestion', TypeGestion::SIMPLE)->firstOrFail();

    expect($simple->registerTypeColleges()->orderBy('id')->pluck('code_college')->all())
        ->toBe(['SIMPLE_LITTERAIRE_EDITION', 'SIMPLE_POESIE', 'SIMPLE_ARTS_GRAPHIQUES', 'LOGICIEL', 'RECHERCHE_SCIENTIFIQUE', 'SITE_WEB', 'THESES_MEMOIRES']);
});

// --- spot checks against the ONDA dump (onda_db.sql), in dump id order --------

test('MUSIQUE matches the dump', function () {
    expect(membersOf('MUSIQUE'))->toBe([
        ['Auteur', 'A', true],
        ['Compositeur', 'C', true],
        ['Arrangeur', 'AR', true],
        ['Adaptateur', 'AD', true],
        ['Auteur-compositeur', 'CA', true],
    ]);
});

test('PRESTATION_AUDIOVISUELLE matches the dump', function () {
    expect(membersOf('PRESTATION_AUDIOVISUELLE'))->toBe([
        ['Choriste', '01', true],
        ['Chanteur', '02', true],
        ["Chef d'orchestre", '03', true],
        ['Comédien', '04', true],
        ['Danseur', '05', true],
        ['Doubleur de voix', '06', true],
        ['Liseur', '07', true],
        ['Musicien', '08', true],
        ['Radio', '10', true],
        ['Télévision', '11', true],
        ['Narrateur', '12', true],
        ['Chanteuse', '13', true],
        ['Comédienne', '14', true],
        ['Danseuse', '16', true],
        ['Musicienne', '17', true],
        ['Magicien', '18', true],
        ['Auto-producteur', '19', true],
        ['Clown', '20', true],
        ["L'Acteur", 'AC', true],
        ['Diseur', 'DI', true],
        ['Conteur', 'CO', true],
    ]);
});

test('PRODUCTION_PHONOGRAMME matches the dump', function () {
    expect(membersOf('PRODUCTION_PHONOGRAMME'))->toBe([
        ['Producteur (trice)', '09', true],
        ['Auto-producteur', '19', true],
        ['Auto-entrepreneur', null, true],
    ]);
});

// --- relationships and the localised name --------------------------------------

test('the relationships connect every level', function () {
    $musique = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();

    expect($musique->registerType->is(auteur()))->toBeTrue()
        ->and($musique->registerTypeMembers)->toHaveCount(5)
        ->and($musique->registerRoleAuteurs()->pluck('name')->all())->toBe(['Compositeur', 'Parolier', 'Adaptateur', 'Arrangeur'])
        ->and($musique->registerTypeMembers->first()?->registerTypeCollege->is($musique))->toBeTrue()
        ->and(TypeGestion::firstOrFail()->registerType->is(auteur()))->toBeTrue()
        ->and(RegisterType::active()->count())->toBe(4)
        ->and(RegisterTypeCollege::active()->count())->toBe(22);
});

test('name_global falls back to name when the translation is null', function () {
    $musique = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();

    app()->setLocale('ar');
    expect($musique->name_ar)->toBeNull()
        ->and($musique->name_global)->toBe(' oeuvres musicales');

    app()->setLocale('en');
    expect($musique->name_global)->toBe(' oeuvres musicales');
});

test('name_global uses the translation when it is filled', function () {
    $collective = auteur()->typeGestions()->where('type_gestion', TypeGestion::COLLECTIVE)->firstOrFail();

    app()->setLocale('ar');
    expect($collective->name_global)->toBe('إدارة جماعية');

    app()->setLocale('en');
    expect($collective->name_global)->toBe('Collective management');

    app()->setLocale('fr');
    expect($collective->name_global)->toBe('Gestion collective');
});
