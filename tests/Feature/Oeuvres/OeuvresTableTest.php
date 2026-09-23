<?php

declare(strict_types=1);

use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\OeuvreStatus;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\RegisterTypeCollege;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * The author's works table: whose rows it shows, what the filters do, and
 * — the one that matters structurally — that its query count does not grow
 * with the number of rows.
 */
function tableAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function tableOeuvre(User $author, string $status = OeuvreStatus::DRAFT, ?string $title = null): Oeuvre
{
    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'title' => $title,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
    ]);
}

function tableClassifiedOeuvre(User $author, string $codeCollege = 'MUSIQUE'): Oeuvre
{
    $college = RegisterTypeCollege::where('code_college', $codeCollege)->firstOrFail();

    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => OeuvreStatus::DRAFT,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
    ]);
}

test('the table lists only the viewing author\'s own oeuvres', function () {
    $author = tableAuthor();
    $stranger = tableAuthor();

    $mine = tableOeuvre($author, title: 'Mine');
    tableOeuvre($stranger, title: 'Theirs');

    $this->actingAs($author)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('author/oeuvres/Index')
            ->has('oeuvres.data', 1)
            ->where('oeuvres.data.0.uuid', $mine->uuid)
            ->where('counts.all', 1)
        );
});

test('the status filter returns only that status', function () {
    $author = tableAuthor();

    tableOeuvre($author, OeuvreStatus::DRAFT);
    $rejected = tableOeuvre($author, OeuvreStatus::REJECTED);
    tableOeuvre($author, OeuvreStatus::REGISTERED);

    $this->actingAs($author)
        ->get(route('oeuvres.index', ['status' => 'rejected']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('oeuvres.data', 1)
            ->where('oeuvres.data.0.uuid', $rejected->uuid)
            // Counts are over the whole shelf, not the filtered page: a tab
            // reading "(3)" must not change when a filter is applied.
            ->where('counts.all', 3)
            ->where('counts.rejected', 1)
        );
});

test('an unknown status filter is ignored rather than returning nothing', function () {
    $author = tableAuthor();
    tableOeuvre($author);

    $this->actingAs($author)
        ->get(route('oeuvres.index', ['status' => 'not-a-status']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('oeuvres.data', 1)
            ->where('filters.status', '')
        );
});

test('search matches the title, and never another author\'s row', function () {
    $author = tableAuthor();
    $stranger = tableAuthor();

    $match = tableOeuvre($author, title: 'Symphonie des Aurès');
    tableOeuvre($author, title: 'Something else entirely');
    tableOeuvre($stranger, title: 'Symphonie des Aurès');

    $this->actingAs($author)
        ->get(route('oeuvres.index', ['search' => 'Aurès']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('oeuvres.data', 1)
            ->where('oeuvres.data.0.uuid', $match->uuid)
            ->where('filters.search', 'Aurès')
        );
});

test('search matches the collège name', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $author = tableAuthor();
    $musical = tableClassifiedOeuvre($author);
    tableOeuvre($author, title: 'Unclassified');

    $college = RegisterTypeCollege::find($musical->register_type_college_id);

    $this->actingAs($author)
        ->get(route('oeuvres.index', ['search' => trim($college->name)]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('oeuvres.data', 1)
            ->where('oeuvres.data.0.uuid', $musical->uuid)
        );
});

test('the documents column reports satisfied required slots', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $author = tableAuthor();
    $oeuvre = tableClassifiedOeuvre($author);

    $slot = $oeuvre->requirements()->required()
        ->whereNull('conditions')->firstOrFail();

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'college_oeuvre_file_id' => $slot->id,
        'status' => MediaFileStatus::READY,
    ]);

    $this->actingAs($author)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('oeuvres.data.0.documents.satisfied', 1)
            // MUSIQUE has six required documents, five of them conditional.
            ->where('oeuvres.data.0.documents.total', 6)
            ->where('oeuvres.data.0.documents.conditional', 5)
        );
});

test('a file that is not `ready` does not satisfy its slot', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $author = tableAuthor();
    $oeuvre = tableClassifiedOeuvre($author);
    $slot = $oeuvre->requirements()->required()->whereNull('conditions')->firstOrFail();

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'college_oeuvre_file_id' => $slot->id,
        'status' => MediaFileStatus::SCANNING,
    ]);

    $this->actingAs($author)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('oeuvres.data.0.documents.satisfied', 0)
            ->where('oeuvres.data.0.can.submit', false)
        );
});

/**
 * The structural assertion. `Oeuvre::requiredDocumentsProgress()` costs two
 * queries per oeuvre; calling it per row would make this table's cost grow
 * linearly. SlotProgressQuery answers for the whole page at once, so the
 * count must be IDENTICAL for one row and for a full page.
 */
test('the query count does not grow as rows are added', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);

    $author = tableAuthor();
    tableClassifiedOeuvre($author);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $measure = function () use ($author, &$queries): int {
        $queries = 0;
        $this->actingAs($author)->get(route('oeuvres.index'))->assertOk();

        return $queries;
    };

    // Warm-up, discarded: spatie/laravel-permission loads and caches its
    // permission table on first use, so the very first request in a test
    // carries queries no later request repeats. Measuring it would compare
    // a cold request against a warm one, not one row against thirteen.
    $measure();

    $withOneRow = $measure();

    // A full page across three different collèges, so the requirement
    // lookup has more than one group to resolve.
    foreach (['LOGICIEL', 'POESIE', 'MUSIQUE'] as $code) {
        for ($i = 0; $i < 4; $i++) {
            $oeuvre = tableClassifiedOeuvre($author, $code);
            MediaFile::factory()->create([
                'oeuvre_id' => $oeuvre->id,
                'uploaded_by' => $author->id,
                'college_oeuvre_file_id' => $oeuvre->requirements()->required()->first()?->id,
                'status' => MediaFileStatus::READY,
            ]);
        }
    }

    $withThirteenRows = $measure();

    expect($withThirteenRows)->toBe($withOneRow)
        // Pinned, not merely "equal": if the page ever grows a seventh
        // query this fails and whoever added it has to say why. The six
        // are enumerated in OeuvreController::index()'s doc comment.
        ->and($withOneRow)->toBe(6);
})->group('query-count');

test('the table paginates instead of returning every row', function () {
    $author = tableAuthor();

    Oeuvre::factory()->count(20)->create([
        'author_id' => $author->id,
        'status' => OeuvreStatus::DRAFT,
    ]);

    $this->actingAs($author)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('oeuvres.data', 15)
            ->where('oeuvres.total', 20)
            ->where('oeuvres.last_page', 2)
        );
});

test('row abilities mirror the policy for every status', function (string $status, bool $editable) {
    $author = tableAuthor();
    tableOeuvre($author, $status);

    $this->actingAs($author)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('oeuvres.data.0.can.edit', $editable)
            ->where('oeuvres.data.0.can.delete', $editable)
        );
})->with([
    'draft' => [OeuvreStatus::DRAFT, true],
    'rejected' => [OeuvreStatus::REJECTED, true],
    'submitted' => [OeuvreStatus::SUBMITTED, false],
    'under_review' => [OeuvreStatus::UNDER_REVIEW, false],
    'registered' => [OeuvreStatus::REGISTERED, false],
]);

test('submit is offered only when the gate would pass', function () {
    $author = tableAuthor();

    // No files at all.
    $empty = tableOeuvre($author);

    $this->actingAs($author)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('oeuvres.data.0.can.submit', false)
        );

    MediaFile::factory()->create([
        'oeuvre_id' => $empty->id,
        'uploaded_by' => $author->id,
        'status' => MediaFileStatus::READY,
    ]);

    $this->actingAs($author)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('oeuvres.data.0.can.submit', true)
        );
});

test('a failed file withdraws the submit action from the row', function () {
    $author = tableAuthor();
    $oeuvre = tableOeuvre($author);

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'status' => MediaFileStatus::READY,
    ]);
    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'status' => MediaFileStatus::FAILED,
    ]);

    $this->actingAs($author)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('oeuvres.data.0.can.submit', false)
        );
});
