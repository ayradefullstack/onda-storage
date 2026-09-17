<?php

declare(strict_types=1);

use App\Models\CollegeOeuvreFile;
use App\Models\RegisterTypeCollege;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
});

/**
 * @return list<array{0: int, 1: string, 2: string, 3: list<string>}>
 */
function requiredDocumentsOf(string $code): array
{
    return RegisterTypeCollege::where('code_college', $code)->firstOrFail()
        ->collegeOeuvreFiles()->ordered()->get()
        ->map(fn (CollegeOeuvreFile $document): array => [$document->display_order, $document->document_key, $document->title, $document->extensions])
        ->all();
}

// --- counts: COLLEGE_DOCUMENTS_MATRIX.md §5.2 ----------------------------------

test('it seeds exactly the 69 rows of the matrix', function () {
    expect(CollegeOeuvreFile::count())->toBe(69);
});

test('the per-college breakdown matches §5.2', function () {
    $counts = RegisterTypeCollege::withCount('collegeOeuvreFiles')->pluck('college_oeuvre_files_count', 'code_college')->all();

    expect($counts)->toEqual([
        'MUSIQUE' => 6, 'DRAMATIQUE' => 4, 'LITTERAIRE_EMISSION' => 4, 'LITTERAIRE_EDITION' => 4,
        'POESIE' => 2, 'ARTS_GRAPHIQUES' => 2, 'REFERENTIEL_HORS_ADHESION' => 1, 'OEUVRE_FILM' => 1,
        'EDITEUR_MUSICAL' => 4, 'PRESTATION_LYRIQUE' => 4, 'PRESTATION_DRAMATIQUE_CHOREGRAPHIQUE' => 5,
        'PRESTATION_LITTERAIRE_EMISSION' => 6, 'PRESTATION_AUDIOVISUELLE' => 6, 'PRODUCTION_PHONOGRAMME' => 4,
        'PRODUCTION_VIDEOGRAMME' => 3, 'SIMPLE_LITTERAIRE_EDITION' => 2, 'SIMPLE_POESIE' => 2,
        'SIMPLE_ARTS_GRAPHIQUES' => 1, 'LOGICIEL' => 3, 'RECHERCHE_SCIENTIFIQUE' => 1, 'SITE_WEB' => 3,
        'THESES_MEMOIRES' => 1,
    ]);
});

test('running it twice changes no counts or row contents', function () {
    $snapshot = fn (): array => CollegeOeuvreFile::orderBy('id')->get()
        ->map->only(['id', 'uuid', 'register_type_college_id', 'document_key', 'title', 'title_ar', 'title_en', 'extensions', 'is_required', 'display_order', 'max_size_kb', 'allows_multiple', 'conditions', 'needs_review'])
        ->all();

    $before = $snapshot();

    $this->seed(CollegeOeuvreFileSeeder::class);

    expect($snapshot())->toBe($before)
        ->and(CollegeOeuvreFile::count())->toBe(69);
});

test('a retired document is updated in place and stays retired', function () {
    CollegeOeuvreFile::where('document_key', 'paroles')->firstOrFail()->delete();

    $this->seed(CollegeOeuvreFileSeeder::class);

    expect(CollegeOeuvreFile::withTrashed()->count())->toBe(69)
        ->and(CollegeOeuvreFile::count())->toBe(68)
        ->and(CollegeOeuvreFile::onlyTrashed()->value('document_key'))->toBe('paroles');
});

test('it fails loudly, naming the code, when a college is missing', function () {
    CollegeOeuvreFile::query()->forceDelete();
    RegisterTypeCollege::where('code_college', 'SITE_WEB')->forceDelete();

    expect(fn () => $this->seed(CollegeOeuvreFileSeeder::class))
        ->toThrow(RuntimeException::class, 'SITE_WEB');

    expect(CollegeOeuvreFile::count())->toBe(0);
});

// --- row invariants -----------------------------------------------------------

test('every row has a non-empty list of lowercase extensions', function () {
    CollegeOeuvreFile::all()->each(function (CollegeOeuvreFile $document) {
        expect($document->extensions)->toBeArray()->not->toBeEmpty()->toBe(array_values($document->extensions));

        foreach ($document->extensions as $extension) {
            expect($extension)->toMatch('/^[a-z0-9]+$/');
        }
    });
});

test('every college in the database has at least one document', function () {
    expect(RegisterTypeCollege::doesntHave('collegeOeuvreFiles')->count())->toBe(0)
        ->and(RegisterTypeCollege::count())->toBe(22);
});

test('document_key is unique per college, and autorisation_auteur recurs across colleges', function () {
    $pairs = CollegeOeuvreFile::all()->map(fn (CollegeOeuvreFile $document): string => $document->register_type_college_id.'-'.$document->document_key);

    expect($pairs->unique())->toHaveCount(69);

    $colleges = CollegeOeuvreFile::where('document_key', 'autorisation_auteur')
        ->with('registerTypeCollege')->get()
        ->pluck('registerTypeCollege.code_college')->sort()->values()->all();

    expect($colleges)->toBe(['DRAMATIQUE', 'LITTERAIRE_EDITION', 'LITTERAIRE_EMISSION', 'POESIE', 'SIMPLE_LITTERAIRE_EDITION', 'SIMPLE_POESIE']);
});

test('the database rejects a second row with the same college and document_key', function () {
    $existing = CollegeOeuvreFile::where('document_key', 'autorisation_auteur')->firstOrFail();

    expect(fn () => CollegeOeuvreFile::factory()->create([
        'register_type_college_id' => $existing->register_type_college_id,
        'document_key' => 'autorisation_auteur',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

// --- spot checks against §5.2 ---------------------------------------------------

test('MUSIQUE matches §5.2', function () {
    expect(requiredDocumentsOf('MUSIQUE'))->toBe([
        [1, 'paroles', 'Paroles', ['pdf']],
        [2, 'enregistrement_oeuvre', "Enregistrement de l'œuvre", ['mp3', 'wav', 'flac']],
        [3, 'justificatif_exploitation', "Justificatif d'exploitation (*)", ['pdf', 'jpg', 'jpeg', 'png']],
        [4, 'autorisation_sample', 'Autorisation du sample', ['png', 'jpg', 'pdf']],
        [5, 'autorisation_compositeur', 'Autorisation du compositeur', ['pdf', 'jpg', 'jpeg', 'png']],
        [6, 'file_editeur', "Justificatif / Contrat d'édition", ['png', 'jpg', 'pdf']],
    ]);

    $paroles = CollegeOeuvreFile::where('document_key', 'paroles')->firstOrFail();

    expect($paroles->title_ar)->toBe('كلمات الأغاني')
        ->and($paroles->conditions)->toMatchArray(['show_when' => ['presence_parole', 'avec_paroles']]);
});

test('EDITEUR_MUSICAL matches §5.2', function () {
    expect(requiredDocumentsOf('EDITEUR_MUSICAL'))->toBe([
        [1, 'paroles_oeuvre', "Paroles de l'œuvre (*)", ['pdf', 'jpg', 'jpeg', 'png']],
        [2, 'cd_commercialise', "Enregistrement de l'œuvre (*)", ['mp3', 'wav', 'flac', 'mp4']],
        [3, 'contrat_edition', "Contrat d'édition (*)", ['pdf', 'jpg', 'jpeg', 'png']],
        [4, 'justificatif_exploitation', "Justificatif d'exploitation (*)", ['pdf', 'jpg', 'jpeg', 'png']],
    ]);
});

test('PRESTATION_AUDIOVISUELLE matches §5.2', function () {
    $document = ['pdf', 'jpg', 'jpeg', 'png'];

    expect(requiredDocumentsOf('PRESTATION_AUDIOVISUELLE'))->toBe([
        [1, 'declaration_enregistrement', "Déclaration d'enregistrement / tournage", $document],
        [2, 'contrat_travail_cession', 'Contrat de travail ou de cession', $document],
        [3, 'attestation_diffusion_tv', 'Attestation de diffusion TV', $document],
        [4, 'fiche_technique_videogramme', 'Fiche technique du vidéogramme', $document],
        [5, 'captures_ecran_prestation', "Captures d'écran ou lien de la prestation", $document],
        [6, 'autre', 'Autre (document)', $document],
    ]);

    expect(CollegeOeuvreFile::whereBelongsTo(RegisterTypeCollege::where('code_college', 'PRESTATION_AUDIOVISUELLE')->firstOrFail())
        ->where('document_key', 'autre')->firstOrFail()->conditions)
        ->toBe(['show_when' => ['types_justificatifs_audiovisuelle', 'autre', 'in']]);
});

test('ARTS_GRAPHIQUES oeuvres_plastiques_graphiques is capped at 102400 KB', function () {
    $document = RegisterTypeCollege::where('code_college', 'ARTS_GRAPHIQUES')->firstOrFail()
        ->collegeOeuvreFiles()->where('document_key', 'oeuvres_plastiques_graphiques')->firstOrFail();

    expect($document->max_size_kb)->toBe(102400);
});

test('only the protection-simple rows of LOGICIEL and SITE_WEB are optional', function () {
    $optional = CollegeOeuvreFile::where('is_required', false)->with('registerTypeCollege')->get()
        ->map(fn (CollegeOeuvreFile $document): string => $document->registerTypeCollege->code_college.'.'.$document->document_key)
        ->sort()->values()->all();

    expect($optional)->toBe(['LOGICIEL.oeuvre_format_numerique', 'LOGICIEL.schema_bdd', 'SITE_WEB.code_source', 'SITE_WEB.schema_bdd'])
        ->and(CollegeOeuvreFile::required()->count())->toBe(65);
});

// --- needs_review ---------------------------------------------------------------

test('needs_review marks the 10 AUCUNE RÈGLE TROUVÉE rows plus the 3 other rows with no enforced type', function () {
    $flagged = CollegeOeuvreFile::where('needs_review', true)->with('registerTypeCollege')->get()
        ->map(fn (CollegeOeuvreFile $document): string => $document->registerTypeCollege->code_college.'.'.$document->document_key)
        ->sort()->values()->all();

    $aucuneRegleTrouvee = [
        'ARTS_GRAPHIQUES.attestation_exposition',
        'DRAMATIQUE.dvd_commercialise',
        'DRAMATIQUE.enregistrement_oeuvre',
        'DRAMATIQUE.oeuvre_dramatique',
        'LITTERAIRE_EMISSION.autorisation_auteur',
        'LITTERAIRE_EMISSION.dvd_commercialise',
        'LITTERAIRE_EMISSION.enregistrement_oeuvre',
        'LITTERAIRE_EMISSION.oeuvre_litteraire',
        'POESIE.oeuvres_poetiques',
        'SIMPLE_POESIE.oeuvres_poetiques',
    ];

    // §5.2 marks these N/A (unreachable colleges) or "aucune restriction de
    // type": no rule either, so the seeded list is a default as well.
    $noEnforcedType = [
        'EDITEUR_MUSICAL.cd_commercialise',
        'OEUVRE_FILM.numerique',
        'REFERENTIEL_HORS_ADHESION.numerique',
    ];

    $expected = [...$aucuneRegleTrouvee, ...$noEnforcedType];
    sort($expected);

    expect($aucuneRegleTrouvee)->toHaveCount(10)
        ->and($flagged)->toBe($expected)
        ->and($flagged)->toHaveCount(13);
});

// --- localisation ---------------------------------------------------------------

test('title_global falls back to title when the translation is null', function () {
    $contrat = RegisterTypeCollege::where('code_college', 'PRODUCTION_PHONOGRAMME')->firstOrFail()
        ->collegeOeuvreFiles()->where('document_key', 'contrat')->firstOrFail();

    expect($contrat->title_ar)->toBeNull();

    app()->setLocale('ar');
    expect($contrat->title_global)->toBe('Contrat de production');

    app()->setLocale('en');
    expect($contrat->title_global)->toBe('Contrat de production');
});

test('title_global returns the translation for the active locale', function () {
    $paroles = CollegeOeuvreFile::where('document_key', 'paroles')->firstOrFail();

    app()->setLocale('ar');
    expect($paroles->title_global)->toBe('كلمات الأغاني');

    app()->setLocale('en');
    expect($paroles->title_global)->toBe('Lyrics');

    app()->setLocale('fr');
    expect($paroles->title_global)->toBe('Paroles');
});
