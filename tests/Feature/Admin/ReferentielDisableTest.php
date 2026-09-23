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
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Disabling a college is the action with the widest blast radius, so these
 * pin the three properties the brief requires of it:
 *
 *   1. it stops the college being selectable in step 1;
 *   2. existing oeuvres keep their classification and stay viewable;
 *   3. a `registered` oeuvre is untouched under any circumstance.
 */
beforeEach(function () {
    $this->seed([MembershipTypeSeeder::class, CollegeOeuvreFileSeeder::class]);
});

function disableAdmin(): User
{
    return User::factory()->withRole('admin')->create();
}

function disableAuthor(): User
{
    return User::factory()->withRole('author')->create(['email_verified_at' => now()]);
}

function collegeToDisable(): RegisterTypeCollege
{
    return RegisterTypeCollege::where('code_college', 'MUSIQUE')->firstOrFail();
}

function oeuvreUnder(RegisterTypeCollege $college, User $author, string $status): Oeuvre
{
    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'register_type_id' => $college->register_type_id,
        'type_gestion_id' => $college->type_gestion_id,
        'register_type_college_id' => $college->id,
        'code_college_snapshot' => $college->code_college,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        'registered_at' => $status === OeuvreStatus::REGISTERED ? now() : null,
    ]);
}

test('toggling is_disabled on a college removes it from the step 1 cascade', function () {
    $college = collegeToDisable();
    $author = disableAuthor();

    // Selectable to begin with.
    $this->actingAs($author)
        ->get(route('oeuvres.create'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'classification.types',
            fn ($types) => collect($types)
                ->flatMap(fn (array $t) => $t['colleges'])
                ->contains(fn (array $c) => $c['code_college'] === 'MUSIQUE' && $c['is_disabled'] === false)
        ));

    $this->actingAs(disableAdmin())
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => true])
        ->assertRedirect();

    // Still listed — the cascade renders a disabled college unselectable
    // rather than hiding it (scopeAvailableForRegistration's contract) —
    // but now flagged so the form refuses it.
    $this->actingAs($author)
        ->get(route('oeuvres.create'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'classification.types',
            fn ($types) => collect($types)
                ->flatMap(fn (array $t) => $t['colleges'])
                ->contains(fn (array $c) => $c['code_college'] === 'MUSIQUE' && $c['is_disabled'] === true)
        ));
});

test('a disabled college cannot be chosen for a new oeuvre', function () {
    $college = collegeToDisable();
    $author = disableAuthor();

    $this->actingAs(disableAdmin())
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => true]);

    $member = $college->registerTypeMembers()->where('available_in_registration', true)->firstOrFail();

    $this->actingAs($author)
        ->post(route('oeuvres.store'), [
            'register_type_id' => $college->register_type_id,
            'type_gestion_id' => $college->type_gestion_id,
            'register_type_college_id' => $college->id,
            'register_type_member_id' => $member->id,
        ])
        ->assertInvalid('register_type_college_id');

    expect(Oeuvre::where('register_type_college_id', $college->id)->count())->toBe(0);
});

test('disabling a college does not change existing oeuvres classified under it', function () {
    $college = collegeToDisable();
    $author = disableAuthor();

    $draft = oeuvreUnder($college, $author, OeuvreStatus::DRAFT);
    $submitted = oeuvreUnder($college, $author, OeuvreStatus::SUBMITTED);

    $before = [
        $draft->id => $draft->only(['status', 'register_type_id', 'register_type_college_id', 'code_college_snapshot']),
        $submitted->id => $submitted->only(['status', 'register_type_id', 'register_type_college_id', 'code_college_snapshot']),
    ];

    $this->actingAs(disableAdmin())
        ->patch(route('admin.referentiel.colleges.update', $college), [
            'is_disabled' => true,
            'status' => 0,
        ])
        ->assertRedirect();

    foreach ($before as $id => $snapshot) {
        expect(Oeuvre::findOrFail($id)->only(array_keys($snapshot)))->toBe($snapshot);
    }

    // And the author can still open their own deposit — a retired college
    // must not lock an author out of a deposit already filed under it.
    $this->actingAs($author)->get(route('oeuvres.show', $draft))->assertOk();
    $this->actingAs($author)->get(route('oeuvres.show', $submitted))->assertOk();
});

test('a disabled college stays reviewable by an officer', function () {
    $college = collegeToDisable();
    $author = disableAuthor();
    $admin = disableAdmin();

    $submitted = oeuvreUnder($college, $author, OeuvreStatus::SUBMITTED);

    $this->actingAs($admin)
        ->patch(route('admin.referentiel.colleges.update', $college), ['is_disabled' => true]);

    // The whole point of retiring rather than deleting: work in the queue
    // is still work in the queue.
    $this->actingAs($admin)->get(route('admin.oeuvres.show', $submitted))->assertOk();

    $this->actingAs($admin)
        ->post(route('admin.oeuvres.review', $submitted))
        ->assertRedirect();

    expect($submitted->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);
});

test('a registered oeuvre is unaffected by any reference-data change', function () {
    $college = collegeToDisable();
    $author = disableAuthor();
    $admin = disableAdmin();

    $registered = oeuvreUnder($college, $author, OeuvreStatus::REGISTERED);
    $classification = [
        'status', 'register_type_id', 'type_gestion_id',
        'register_type_college_id', 'register_type_member_id',
        'code_college_snapshot',
    ];
    $before = $registered->only($classification);
    $registeredAt = $registered->registered_at?->toIso8601String();

    $member = $college->registerTypeMembers()->firstOrFail();
    $document = $college->collegeOeuvreFiles()->firstOrFail();

    // Retire the college, unpublish it, retire a qualité under it, and
    // rewrite the document rules — everything this console can do.
    $this->actingAs($admin)->patch(route('admin.referentiel.colleges.update', $college), [
        'is_disabled' => true,
        'status' => 0,
        'name_ar' => 'اسم مختلف',
        'adhesion' => false,
    ]);
    $this->actingAs($admin)->patch(route('admin.referentiel.membres.update', $member), [
        'is_disabled' => true,
        'status' => 0,
        'available_in_registration' => false,
    ]);
    $this->actingAs($admin)->patch(route('admin.referentiel.documents.update', $document), [
        'is_required' => true,
        'extensions' => ['pdf'],
        'max_size_kb' => 1024,
        'allows_multiple' => false,
    ]);

    $after = Oeuvre::findOrFail($registered->id);

    expect($after->only($classification))->toBe($before)
        ->and($after->registered_at?->toIso8601String())->toBe($registeredAt);

    // And it still opens, for both sides.
    $this->actingAs($author)->get(route('oeuvres.show', $registered))->assertOk();
    $this->actingAs($admin)->get(route('admin.oeuvres.show', $registered))->assertOk();
});

test('tightening a document rule does not invalidate files already deposited', function () {
    $college = collegeToDisable();
    $author = disableAuthor();
    $admin = disableAdmin();

    $document = $college->collegeOeuvreFiles()->firstOrFail();
    $oeuvre = oeuvreUnder($college, $author, OeuvreStatus::DRAFT);

    $file = MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'college_oeuvre_file_id' => $document->id,
        'extension' => 'pdf',
        'status' => MediaFileStatus::READY,
        'size_bytes' => 5_000_000,
    ]);

    // Narrow the rule to something this file would now fail.
    $this->actingAs($admin)->patch(route('admin.referentiel.documents.update', $document), [
        'extensions' => ['jpg'],
        'max_size_kb' => 10,
    ]);

    $file->refresh();

    // The deposited file is untouched: it was valid when it was deposited,
    // and a rule change does not reach back in time.
    expect($file->trashed())->toBeFalse()
        ->and($file->status)->toBe(MediaFileStatus::READY)
        ->and($file->extension)->toBe('pdf')
        ->and($file->size_bytes)->toBe(5_000_000);
});
