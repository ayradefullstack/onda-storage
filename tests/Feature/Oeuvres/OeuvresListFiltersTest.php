<?php

declare(strict_types=1);

use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Models\Oeuvre;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * The author's list as the filter controls drive it (useListFilters): one
 * request per filter change, a partial reload of `oeuvres` + `counts` +
 * `filters`. The server side of "the URL changes but the list does not":
 * whatever the client asks for, the answer is the right rows, only ever
 * the author's own, and an unexpected value degrades to "no filter".
 */
function filtersAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

/** One oeuvre per status for `$author`; returns them keyed by status. */
function filtersShelf(User $author): array
{
    $shelf = [];

    foreach ([
        OeuvreStatus::DRAFT,
        OeuvreStatus::SUBMITTED,
        OeuvreStatus::UNDER_REVIEW,
        OeuvreStatus::REGISTERED,
        OeuvreStatus::REJECTED,
    ] as $status) {
        $shelf[$status->key()] = Oeuvre::factory()->create([
            'author_id' => $author->id,
            'status' => $status,
            'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        ]);
    }

    return $shelf;
}

test('each status filter returns exactly that status, and never another author\'s row', function (OeuvreStatus $status) {
    $author = filtersAuthor();
    $mine = filtersShelf($author);
    // Same statuses on someone else's shelf: none of these may leak.
    $theirs = filtersShelf(filtersAuthor());

    $this->actingAs($author)
        ->get(route('oeuvres.index', ['status' => $status->key()]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('author/oeuvres/Index')
            ->has('oeuvres.data', 1)
            ->where('oeuvres.data.0.uuid', $mine[$status->key()]->uuid)
            ->where('oeuvres.data.0.status', $status->key())
            ->where('filters.status', $status->key())
            ->where('counts.all', 5)
        );

    expect($theirs[$status->key()]->author_id)->not->toBe($author->id);
})->with([
    OeuvreStatus::DRAFT,
    OeuvreStatus::SUBMITTED,
    OeuvreStatus::UNDER_REVIEW,
    OeuvreStatus::REGISTERED,
    OeuvreStatus::REJECTED,
]);

test('the pending filter returns submitted and under review, own rows only', function () {
    $author = filtersAuthor();
    $mine = filtersShelf($author);
    filtersShelf(filtersAuthor());

    $this->actingAs($author)
        ->get(route('oeuvres.index', ['status' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('oeuvres.data', 2)
            ->where('filters.status', 'pending')
            ->where('oeuvres.data', fn ($rows) => collect($rows)->pluck('uuid')->sort()->values()->all()
                === collect([$mine['submitted']->uuid, $mine['under_review']->uuid])->sort()->values()->all())
        );
});

test('unknown or hostile filter values are ignored safely', function (array $query) {
    $author = filtersAuthor();
    filtersShelf($author);
    filtersShelf(filtersAuthor());

    $this->actingAs($author)
        ->get(route('oeuvres.index', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Every own row, none of the stranger's five.
            ->has('oeuvres.data', 5)
            ->where('filters.status', '')
            ->where('filters.sort', 'newest')
        );
})->with([
    'unknown status' => [['status' => 'archived']],
    'sql-ish status' => [['status' => "draft' OR '1'='1"]],
    'status as array' => [['status' => ['draft', 'rejected']]],
    'unknown sort' => [['sort' => 'id; drop table oeuvres']],
    'empty values' => [['status' => '', 'search' => '', 'sort' => '']],
]);

test('the partial reload the filter controls send returns the filtered list', function () {
    $author = filtersAuthor();
    $mine = filtersShelf($author);
    filtersShelf(filtersAuthor());

    $this->actingAs($author)
        ->get(route('oeuvres.index', ['status' => OeuvreStatus::REGISTERED->key()]))
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['oeuvres', 'counts', 'filters'], fn (Assert $reload) => $reload
                ->has('oeuvres.data', 1)
                ->where('oeuvres.data.0.uuid', $mine['registered']->uuid)
                ->where('filters.status', OeuvreStatus::REGISTERED->key())
                ->where('counts.all', 5)
                ->missing('statuses')
            )
        );
});

test('clearing the filters (an empty query) brings the whole own list back', function () {
    $author = filtersAuthor();
    filtersShelf($author);
    filtersShelf(filtersAuthor());

    $this->actingAs($author)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('oeuvres.data', 5)
            ->where('filters', ['search' => '', 'status' => '', 'sort' => 'newest'])
        );
});

test('search and status combine, and a filter lands on page 1', function () {
    $author = filtersAuthor();
    $match = Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => OeuvreStatus::REGISTERED,
        'title' => 'Symphonie du désert',
        'submitted_at' => now(),
    ]);
    Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => OeuvreStatus::DRAFT,
        'title' => 'Symphonie inachevée',
    ]);
    Oeuvre::factory()->create([
        'author_id' => filtersAuthor()->id,
        'status' => OeuvreStatus::REGISTERED,
        'title' => 'Symphonie volée',
        'submitted_at' => now(),
    ]);

    $this->actingAs($author)
        ->get(route('oeuvres.index', ['search' => 'Symphonie', 'status' => OeuvreStatus::REGISTERED->key()]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('oeuvres.data', 1)
            ->where('oeuvres.data.0.uuid', $match->uuid)
            ->where('oeuvres.current_page', 1)
        );
});
