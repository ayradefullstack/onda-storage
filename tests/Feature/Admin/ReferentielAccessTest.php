<?php

declare(strict_types=1);

use App\Models\CollegeOeuvreFile;
use App\Models\RegisterType;
use App\Models\RegisterTypeCollege;
use App\Models\RegisterTypeMember;
use App\Models\TypeGestion;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may reach the reference-data console, what each tab lists, and — the
 * structural one — that a tab's cost does not grow with its row count.
 */
beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
});

function referentielAdmin(): User
{
    return User::factory()->withRole('admin')->create();
}

function referentielAuthor(): User
{
    return User::factory()->withRole('author')->create();
}

/** @return list<string> */
function referentielTabs(): array
{
    return ['types', 'gestions', 'colleges', 'membres', 'documents'];
}

test('an author cannot reach any referentiel route', function (string $tab) {
    $this->actingAs(referentielAuthor())
        ->get(route("admin.referentiel.{$tab}"))
        ->assertStatus(Response::HTTP_FORBIDDEN);
})->with(referentielTabs());

test('a guest is redirected away from every referentiel route', function (string $tab) {
    $this->get(route("admin.referentiel.{$tab}"))->assertRedirect();
})->with(referentielTabs());

test('an admin reaches every referentiel route', function (string $tab) {
    $this->actingAs(referentielAdmin())
        ->get(route("admin.referentiel.{$tab}"))
        ->assertOk();
})->with(referentielTabs());

test('each tab lists the seeded rows and the tab bar carries every count', function () {
    $admin = referentielAdmin();

    $expected = [
        'types' => RegisterType::count(),
        'gestions' => TypeGestion::count(),
        'colleges' => RegisterTypeCollege::count(),
        'membres' => RegisterTypeMember::count(),
        'documents' => CollegeOeuvreFile::count(),
    ];

    // The seeded reference data, asserted as the concrete numbers the brief
    // names — if a seeder drifts, this says so rather than comparing a
    // count to itself.
    expect($expected)->toBe([
        'types' => 4,
        'gestions' => 3,
        'colleges' => 22,
        'membres' => 100,
        'documents' => 69,
    ]);

    foreach ($expected as $tab => $total) {
        $this->actingAs($admin)
            ->get(route("admin.referentiel.{$tab}"))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.total', $total)
                ->has('tabs', 5)
                ->where('tabs.0.count', $expected['types'])
                ->where('tabs.2.count', $expected['colleges'])
                ->where('tabs.4.count', $expected['documents'])
            );
    }
});

test('the colleges filter narrows by type, and search by name or code', function () {
    $admin = referentielAdmin();
    $musique = RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();

    $this->actingAs($admin)
        ->get(route('admin.referentiel.colleges', ['search' => 'MUSIQUE']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 1)
            ->where('rows.data.0.code_college', 'MUSIQUE')
        );

    $this->actingAs($admin)
        ->get(route('admin.referentiel.colleges', ['type' => $musique->registerType->uuid]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.total', RegisterTypeCollege::where('register_type_id', $musique->register_type_id)->count())
        );
});

test('the documents needs_review filter returns only flagged rows', function () {
    $flagged = CollegeOeuvreFile::where('needs_review', true)->count();

    expect($flagged)->toBeGreaterThan(0);

    $this->actingAs(referentielAdmin())
        ->get(route('admin.referentiel.documents', ['needs_review' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('rows.total', $flagged));
});

/**
 * The structural assertion, per tab.
 *
 * The counts on each row are aggregates. Loaded as relations instead of
 * `withCount`, the members tab alone would be 100 extra queries. So the
 * cost must be IDENTICAL for a nearly-empty page and a full one.
 */
test('a tab\'s query count does not grow with its rows', function (string $tab, string $searchThatMatchesOne) {
    $admin = referentielAdmin();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $measure = function (array $params) use ($admin, $tab, &$queries): int {
        $queries = 0;
        $this->actingAs($admin)->get(route("admin.referentiel.{$tab}", $params))->assertOk();

        return $queries;
    };

    // Warm-up, discarded: spatie/laravel-permission caches its permission
    // table on first use, so the first request in a test carries queries no
    // later request repeats.
    $measure([]);

    $oneRow = $measure(['search' => $searchThatMatchesOne]);
    $fullPage = $measure([]);

    // Pinned per tab, not merely "equal to itself": if a tab grows an
    // extra query this fails and whoever added it has to say why.
    // Measured, then pinned. Each is: paginator count + the page itself
    // (its withCount aggregates fold into that one statement) + eager-loaded
    // parents + the five tab counts + any filter lookup the tab renders.
    // `colleges` is highest because it carries four aggregates and two
    // filter dropdowns.
    $pinned = [
        'types' => 7,
        // 8 + the declarant types for the create form's parent select.
        'gestions' => 9,
        'colleges' => 11,
        'membres' => 9,
        'documents' => 9,
    ];

    expect($fullPage)->toBe($oneRow)
        ->and($fullPage)->toBe($pinned[$tab]);
})->with([
    // Each search matches exactly one seeded row, so the "few rows" case is
    // a real page with a real filter rather than an empty table.
    'types' => ['types', 'Auteur'],
    'gestions' => ['gestions', 'collective'],
    'colleges' => ['colleges', 'MUSIQUE'],
    'membres' => ['membres', 'Compositeur'],
    'documents' => ['documents', 'justificatif_exploitation'],
])->group('query-count');

test('no route deletes a reference row', function () {
    $referentielRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.referentiel.'))
        ->flatMap(fn ($route) => array_diff($route->methods(), ['HEAD']))
        ->unique()
        ->sort()
        ->values()
        ->all();

    // Reads, creates (POST) and edits (PATCH) only — never DELETE. A collège
    // with deposits filed under it must never disappear, and soft-deleting
    // one would leave those oeuvres pointing at a trashed parent.
    expect($referentielRoutes)->toBe(['GET', 'PATCH', 'POST']);
});

it('honours an allowed per_page on every reference tab and ignores anything else', function () {
    $admin = referentielAdmin();

    foreach (['types', 'gestions', 'colleges', 'membres', 'documents'] as $tab) {
        $this->actingAs($admin)
            ->get(route("admin.referentiel.{$tab}", ['per_page' => 10]))
            ->assertInertia(fn ($page) => $page->where('rows.per_page', 10));

        $this->actingAs($admin)
            ->get(route("admin.referentiel.{$tab}", ['per_page' => 100000]))
            ->assertInertia(fn ($page) => $page->where('rows.per_page', 25));
    }
});
