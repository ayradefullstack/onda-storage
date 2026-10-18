<?php

use App\Domain\Deposit\SubmissionGate;
use App\Enums\MediaFile\StatusEnum as MediaFileStatus;
use App\Enums\Oeuvre\StatusEnum as OeuvreStatus;
use App\Enums\UploadSession\StatusEnum as UploadSessionStatus;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\RegisterTypeCollege;
use App\Models\StorageQuota;
use App\Models\UploadSession;
use App\Models\User;
use Database\Seeders\CollegeOeuvreFileSeeder;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

/**
 * The author dashboard. Every block is server data scoped to the signed-in
 * author, derived from the same rules the rest of the deposit flow uses —
 * nothing on the page is a placeholder.
 */
function dashAuthor(string $name = 'Amina'): User
{
    return User::factory()->withRole('author')->create(['name' => $name]);
}

function dashOeuvre(User $author, OeuvreStatus $status = OeuvreStatus::DRAFT, array $attributes = []): Oeuvre
{
    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        ...$attributes,
    ]);
}

function dashClassified(User $author, string $code = 'MUSIQUE', OeuvreStatus $status = OeuvreStatus::DRAFT): Oeuvre
{
    $college = RegisterTypeCollege::where('code_college', $code)->firstOrFail();

    return dashOeuvre($author, $status, [
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
    ]);
}

function dashFile(Oeuvre $oeuvre, MediaFileStatus $status = MediaFileStatus::READY, array $attributes = []): MediaFile
{
    return MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $oeuvre->author_id,
        'status' => $status,
        ...$attributes,
    ]);
}

/** @return array<string, mixed> */
function dashProps(User $author): array
{
    $props = [];

    test()->actingAs($author)
        ->get(route('author.dashboard'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props): void {
            $page->component('author/Dashboard');
            $props = $page->toArray()['props'];
        });

    return $props;
}

test('guests are redirected to the login page', function () {
    $response = $this->get(route('author.dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->withRole('author')->create();
    $this->actingAs($user);

    $response = $this->get(route('author.dashboard'));
    $response->assertOk();
});

test('an admin is refused by the author role gate', function () {
    Role::findOrCreate('admin');
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('admin');

    $this->actingAs($admin)->get(route('author.dashboard'))->assertForbidden();
});

test('a first-time author gets zeros and nothing else', function () {
    $props = dashProps(dashAuthor());

    expect($props['counts'])->toBe([
        'draft' => 0, 'submitted' => 0, 'under_review' => 0, 'registered' => 0, 'rejected' => 0,
        'all' => 0, 'pending' => 0,
    ])
        ->and($props['actions'])->toBe(['items' => [], 'total' => 0])
        ->and($props['recent'])->toBe([])
        ->and($props['quota'])->toBeNull()
        ->and($props['decisions'])->toBe([]);
});

test('counts are the author\'s own, pending is submitted plus under review, soft-deleted oeuvres are gone', function () {
    $author = dashAuthor();
    $stranger = dashAuthor('Stranger');

    dashOeuvre($author);
    dashOeuvre($author, OeuvreStatus::SUBMITTED);
    dashOeuvre($author, OeuvreStatus::UNDER_REVIEW);
    dashOeuvre($author, OeuvreStatus::UNDER_REVIEW);
    dashOeuvre($author, OeuvreStatus::REGISTERED);
    dashOeuvre($author, OeuvreStatus::DRAFT)->delete();
    dashOeuvre($stranger, OeuvreStatus::REJECTED);
    dashOeuvre($stranger, OeuvreStatus::SUBMITTED);

    $counts = dashProps($author)['counts'];

    expect($counts)->toBe([
        'draft' => 1, 'submitted' => 1, 'under_review' => 2, 'registered' => 1, 'rejected' => 0,
        'all' => 5, 'pending' => 3,
    ]);

    // The card links to ?status=pending: that list holds the same three.
    $this->actingAs($author)
        ->get(route('oeuvres.index', ['status' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page->has('oeuvres.data', 3)->where('counts.pending', 3));
});

test('no other author\'s oeuvre, decision or reason reaches any block', function () {
    $author = dashAuthor();
    $stranger = dashAuthor('Stranger');

    $mine = dashOeuvre($author, OeuvreStatus::REGISTERED, ['title' => 'Mine']);
    $theirs = dashOeuvre($stranger, OeuvreStatus::REJECTED, ['title' => 'Theirs']);
    OeuvreReview::factory()->rejection('Their private reason, never yours to read.')->create(['oeuvre_id' => $theirs->id]);
    OeuvreReview::factory()->create(['oeuvre_id' => $theirs->id, 'from_status' => OeuvreStatus::UNDER_REVIEW, 'to_status' => OeuvreStatus::REGISTERED]);

    $props = dashProps($author);
    $json = json_encode($props);

    expect($json)->not->toContain($theirs->uuid)
        ->and($json)->not->toContain('Their private reason')
        ->and($json)->not->toContain('Theirs')
        ->and(collect($props['recent'])->pluck('uuid')->all())->toBe([$mine->uuid])
        ->and($props['decisions'])->toBe([]);
});

test('a rejected deposit carries the ONDA\'s verbatim reason and the decision date, never the officer', function () {
    $author = dashAuthor();
    $officer = User::factory()->create(['name' => 'Officer Karim']);
    $oeuvre = dashOeuvre($author, OeuvreStatus::REJECTED, ['title' => 'Rai d\'Oran']);

    OeuvreReview::factory()->rejection('An older reason that was already addressed.')->create([
        'oeuvre_id' => $oeuvre->id, 'actor_id' => $officer->id, 'created_at' => now()->subDays(10),
    ]);
    $latest = OeuvreReview::factory()->rejection('Le contrat de cession signé est manquant, merci de le joindre.')->create([
        'oeuvre_id' => $oeuvre->id, 'actor_id' => $officer->id, 'created_at' => now()->subDay(),
    ]);

    $props = dashProps($author);
    $item = $props['actions']['items'][0];

    expect($item['uuid'])->toBe($oeuvre->uuid)
        ->and($item['kind'])->toBe('rejected')
        ->and($item['rejection'])->toBe([
            'reason' => 'Le contrat de cession signé est manquant, merci de le joindre.',
            'at' => $latest->created_at->toIso8601String(),
        ])
        ->and(array_keys($item['rejection']))->toBe(['reason', 'at'])
        ->and(json_encode($props))->not->toContain('Officer Karim');
});

test('what is missing is exactly SubmissionGate\'s verdict, and conditional slots are not missing', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
    $author = dashAuthor();

    // MUSIQUE has unconditional required slots and conditional ones. None
    // is filled; the only file is still in the pipeline.
    $incomplete = dashClassified($author);
    dashFile($incomplete, MediaFileStatus::PROCESSING, ['original_name' => 'master.wav']);

    $items = collect(dashProps($author)['actions']['items']);
    $item = $items->firstWhere('uuid', $incomplete->uuid);
    $verdict = app(SubmissionGate::class)->evaluate($incomplete);

    expect($item['kind'])->toBe('incomplete')
        ->and($item['blockers'])->toBe(array_map(
            fn ($reason) => ['code' => $reason->code, 'params' => $reason->params],
            $verdict->blockers,
        ))
        ->and(collect($item['blockers'])->pluck('code')->unique()->sort()->values()->all())
        ->toBe(['file_in_pipeline', 'slot_empty'])
        // Advisory slots exist on MUSIQUE but never appear as missing.
        ->and($verdict->advisories)->not->toBe([])
        ->and(collect($item['blockers'])->pluck('code'))->not->toContain('slot_empty_conditional');
});

test('a draft is "ready" only when the real gate passes', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
    $author = dashAuthor();

    $ready = dashClassified($author, 'LOGICIEL');
    foreach ($ready->requirements()->required()->whereNull('conditions')->get() as $slot) {
        dashFile($ready, attributes: ['college_oeuvre_file_id' => $slot->id]);
    }
    $empty = dashOeuvre($author);

    $items = collect(dashProps($author)['actions']['items'])->keyBy('uuid');

    expect(app(SubmissionGate::class)->evaluate($ready)->passes())->toBeTrue()
        ->and($items[$ready->uuid]['kind'])->toBe('ready')
        ->and($items[$ready->uuid]['blockers'])->toBe([])
        ->and($items[$empty->uuid]['kind'])->toBe('incomplete')
        ->and($items[$empty->uuid]['blockers'])->toBe([['code' => 'no_files', 'params' => []]]);
});

test('submitted, under-review and registered deposits are never actionable', function () {
    $author = dashAuthor();
    dashFile(dashOeuvre($author, OeuvreStatus::SUBMITTED));
    dashFile(dashOeuvre($author, OeuvreStatus::UNDER_REVIEW));
    dashFile(dashOeuvre($author, OeuvreStatus::REGISTERED));

    expect(dashProps($author)['actions'])->toBe(['items' => [], 'total' => 0]);
});

test('actions list rejected deposits first, capped at five, with the full total', function () {
    $author = dashAuthor();

    foreach (range(1, 6) as $i) {
        dashOeuvre($author, attributes: ['created_at' => now()->subMinutes(10 - $i)]);
    }
    $rejected = dashOeuvre($author, OeuvreStatus::REJECTED, ['created_at' => now()->subYear()]);

    $actions = dashProps($author)['actions'];

    expect($actions['items'])->toHaveCount(5)
        ->and($actions['items'][0]['uuid'])->toBe($rejected->uuid)
        ->and($actions['total'])->toBe(7);
});

test('recent works follow the latest activity, file activity included, five at most', function () {
    $author = dashAuthor();

    $old = dashOeuvre($author, attributes: ['updated_at' => now()->subDays(30)]);
    foreach (range(1, 5) as $i) {
        dashOeuvre($author, attributes: ['updated_at' => now()->subDays(10 + $i)]);
    }
    // Only a file moved on the old oeuvre — its own row did not change.
    dashFile($old, attributes: ['updated_at' => now()->subHour()]);

    $recent = dashProps($author)['recent'];

    expect($recent)->toHaveCount(5)
        ->and($recent[0]['uuid'])->toBe($old->uuid)
        ->and($recent[0]['status'])->toBe(OeuvreStatus::DRAFT->key())
        ->and($recent[0]['label_source']['uuid'])->toBe($old->uuid)
        ->and($recent[0]['last_activity_at'])->not->toBeNull();
});

test('storage is used plus live reservations against the quota row, as InitUpload counts it', function () {
    $author = dashAuthor();
    $oeuvre = dashOeuvre($author);
    StorageQuota::factory()->create(['user_id' => $author->id, 'used_bytes' => 4_000, 'limit_bytes' => 10_000]);

    UploadSession::factory()->create(['user_id' => $author->id, 'oeuvre_id' => $oeuvre->id, 'size_bytes' => 1_500]);
    // Released reservations: expired and aborted sessions do not count.
    UploadSession::factory()->create(['user_id' => $author->id, 'oeuvre_id' => $oeuvre->id, 'size_bytes' => 900, 'expires_at' => now()->subMinute()]);
    UploadSession::factory()->create(['user_id' => $author->id, 'oeuvre_id' => $oeuvre->id, 'size_bytes' => 700, 'status' => UploadSessionStatus::ABORTED]);
    // Someone else's reservation.
    $stranger = dashAuthor('Stranger');
    UploadSession::factory()->create(['user_id' => $stranger->id, 'oeuvre_id' => dashOeuvre($stranger)->id, 'size_bytes' => 5_000]);

    expect(dashProps($author)['quota'])->toBe([
        'used_bytes' => 4_000,
        'reserved_bytes' => 1_500,
        'limit_bytes' => 10_000,
    ]);
});

test('without a quota row no limit is enforced, so none is shown', function () {
    $author = dashAuthor();
    dashOeuvre($author);

    expect(StorageQuota::where('user_id', $author->id)->exists())->toBeFalse()
        ->and(dashProps($author)['quota'])->toBeNull();
});

test('decisions are the three newest ONDA transitions, without the officer, linked to the right oeuvre', function () {
    $author = dashAuthor();
    $officer = User::factory()->create(['name' => 'Officer Karim']);
    $a = dashOeuvre($author, OeuvreStatus::REGISTERED, ['title' => 'A']);
    $b = dashOeuvre($author, OeuvreStatus::REJECTED, ['title' => 'B']);

    $row = fn (Oeuvre $o, OeuvreStatus $from, OeuvreStatus $to, int $minutesAgo) => OeuvreReview::factory()->create([
        'oeuvre_id' => $o->id, 'actor_id' => $officer->id, 'from_status' => $from, 'to_status' => $to,
        'reason' => $to === OeuvreStatus::REJECTED ? 'Une raison suffisamment longue pour un rejet.' : null,
        'created_at' => now()->subMinutes($minutesAgo),
    ]);

    // The author's own submission is not a decision.
    $row($a, OeuvreStatus::DRAFT, OeuvreStatus::SUBMITTED, 50);
    $row($a, OeuvreStatus::SUBMITTED, OeuvreStatus::UNDER_REVIEW, 40);
    $registered = $row($a, OeuvreStatus::UNDER_REVIEW, OeuvreStatus::REGISTERED, 30);
    $row($b, OeuvreStatus::DRAFT, OeuvreStatus::SUBMITTED, 25);
    $opened = $row($b, OeuvreStatus::SUBMITTED, OeuvreStatus::UNDER_REVIEW, 20);
    $rejected = $row($b, OeuvreStatus::UNDER_REVIEW, OeuvreStatus::REJECTED, 10);

    $props = dashProps($author);

    expect($props['decisions'])->toHaveCount(3)
        ->and(array_column($props['decisions'], 'uuid'))->toBe([$rejected->uuid, $opened->uuid, $registered->uuid])
        ->and(array_column($props['decisions'], 'to_status'))->toBe(['rejected', 'under_review', 'registered'])
        ->and(array_column($props['decisions'], 'oeuvre_uuid'))->toBe([$b->uuid, $b->uuid, $a->uuid])
        ->and($props['decisions'][0]['at'])->toBe($rejected->created_at->toIso8601String())
        ->and(array_keys($props['decisions'][0]))->toBe(['uuid', 'oeuvre_uuid', 'label_source', 'to_status', 'at'])
        ->and(json_encode($props['decisions']))->not->toContain('Officer Karim');
});

test('no sequential id is sent anywhere on the page', function () {
    $author = dashAuthor();
    $oeuvre = dashOeuvre($author, OeuvreStatus::REJECTED);
    OeuvreReview::factory()->rejection()->create(['oeuvre_id' => $oeuvre->id]);

    $props = dashProps($author);
    unset($props['auth'], $props['errors'], $props['flash'], $props['languages'], $props['ziggy']);

    expect(json_encode([$props['counts'], $props['actions'], $props['recent'], $props['quota'], $props['decisions']]))
        ->not->toContain('"id"')
        ->not->toContain('oeuvre_id');
});

/**
 * The structural assertion: the page costs the same with 3 oeuvres and with
 * 30. SubmissionGate (2 queries per oeuvre) runs on at most five of them —
 * the real gate, not an approximation — so that cap is what keeps it flat.
 */
test('the query count does not grow with the shelf', function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
    $author = dashAuthor();
    $officer = User::factory()->create();
    StorageQuota::factory()->create(['user_id' => $author->id]);

    $make = function (int $count) use ($author, $officer): void {
        $statuses = [OeuvreStatus::DRAFT, OeuvreStatus::REJECTED, OeuvreStatus::SUBMITTED, OeuvreStatus::UNDER_REVIEW, OeuvreStatus::REGISTERED];

        foreach (range(1, $count) as $i) {
            $status = $statuses[$i % count($statuses)];
            $oeuvre = dashClassified($author, ['MUSIQUE', 'LOGICIEL', 'POESIE'][$i % 3], $status);
            dashFile($oeuvre);

            if ($status !== OeuvreStatus::DRAFT && $status !== OeuvreStatus::SUBMITTED) {
                OeuvreReview::factory()->create([
                    'oeuvre_id' => $oeuvre->id, 'actor_id' => $officer->id,
                    'from_status' => $status === OeuvreStatus::UNDER_REVIEW ? OeuvreStatus::SUBMITTED : OeuvreStatus::UNDER_REVIEW,
                    'to_status' => $status,
                    'reason' => $status === OeuvreStatus::REJECTED ? 'Une raison suffisamment longue pour un rejet.' : null,
                ]);
            }
        }
    };

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $measure = function () use ($author, &$queries): int {
        $queries = 0;
        $this->actingAs($author)->get(route('author.dashboard'))->assertOk();

        return $queries;
    };

    $make(3);
    $measure(); // warm-up: spatie's permission cache, discarded
    $withThree = $measure();

    $make(27);
    $withThirty = $measure();

    $make(30);
    $withSixty = $measure();

    // 3 oeuvres hold one actionable deposit (gate: 2 queries); from five
    // actionable ones on, the cap holds and the count stops moving. The
    // budget is enumerated in DashboardController's doc comment. One of the
    // three is under review: its replacement item costs a fixed 4 more
    // (oeuvres, collège, removed files, review counts).
    expect(Oeuvre::where('author_id', $author->id)->count())->toBe(60)
        ->and($withThree)->toBe(17)
        ->and($withThirty)->toBe(25)
        ->and($withSixty)->toBe($withThirty);
})->group('query-count');
