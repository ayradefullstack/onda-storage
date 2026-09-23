<?php

declare(strict_types=1);

use App\Domain\Deposit\Exceptions\IllegalTransition;
use App\Domain\Deposit\Exceptions\ReasonRequired;
use App\Domain\Deposit\OeuvreStatus;
use App\Domain\Deposit\OeuvreStatusMachine;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * The officer's half of the machine: opening a deposit, deciding on it,
 * the holder guard, and the terminality of `registered`.
 *
 * No collège here — an unclassified oeuvre has no required slots, which
 * keeps these tests about the transitions rather than about the gate
 * (that is SubmissionGateTest's job).
 */
function reviewAdmin(string $name = 'Officer'): User
{
    Role::findOrCreate('admin');
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    $user->assignRole('admin');

    return $user;
}

function reviewAuthorUser(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function reviewOeuvre(User $author, string $status = OeuvreStatus::SUBMITTED, ?User $holder = null): Oeuvre
{
    $oeuvre = Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
        'reviewed_by' => $holder?->id,
        'reviewed_at' => $holder === null ? null : now(),
        'registered_at' => $status === OeuvreStatus::REGISTERED ? now() : null,
    ]);

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'status' => 'ready',
    ]);

    return $oeuvre;
}

test('an admin opens a submitted deposit and takes hold of it', function () {
    $admin = reviewAdmin();
    $oeuvre = reviewOeuvre(reviewAuthorUser());

    $this->actingAs($admin)
        ->post(route('admin.oeuvres.review', $oeuvre))
        ->assertRedirect();

    $oeuvre->refresh();

    expect($oeuvre->status)->toBe(OeuvreStatus::UNDER_REVIEW)
        ->and($oeuvre->reviewed_by)->toBe($admin->id)
        ->and(OeuvreReview::where('oeuvre_id', $oeuvre->id)->count())->toBe(1);
});

test('approving writes a review row and sets registered_at', function () {
    $admin = reviewAdmin();
    $oeuvre = reviewOeuvre(reviewAuthorUser(), OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)
        ->post(route('admin.oeuvres.approve', $oeuvre))
        ->assertRedirect();

    $oeuvre->refresh();
    $review = OeuvreReview::where('oeuvre_id', $oeuvre->id)->latest('id')->first();

    expect($oeuvre->status)->toBe(OeuvreStatus::REGISTERED)
        ->and($oeuvre->registered_at)->not->toBeNull()
        ->and($review->from_status)->toBe(OeuvreStatus::UNDER_REVIEW)
        ->and($review->to_status)->toBe(OeuvreStatus::REGISTERED)
        ->and($review->actor_id)->toBe($admin->id);
});

test('rejecting without a reason is refused', function () {
    $admin = reviewAdmin();
    $oeuvre = reviewOeuvre(reviewAuthorUser(), OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)
        ->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => ''])
        ->assertInvalid('reason');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW)
        ->and(OeuvreReview::where('oeuvre_id', $oeuvre->id)->count())->toBe(0);
});

test('a whitespace-only reason is refused by the machine itself', function () {
    $admin = reviewAdmin();
    $oeuvre = reviewOeuvre(reviewAuthorUser(), OeuvreStatus::UNDER_REVIEW, holder: $admin);
    $machine = app(OeuvreStatusMachine::class);

    expect(fn () => $machine->transition($oeuvre, OeuvreStatus::REJECTED, $admin, '   '))
        ->toThrow(ReasonRequired::class);

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);
});

test('rejecting stores the reason verbatim on the review row', function () {
    $admin = reviewAdmin();
    $oeuvre = reviewOeuvre(reviewAuthorUser(), OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $reason = 'Le justificatif d\'exploitation est illisible — merci de redéposer un scan net.';

    $this->actingAs($admin)
        ->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => $reason])
        ->assertRedirect();

    $review = OeuvreReview::where('oeuvre_id', $oeuvre->id)->latest('id')->first();

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REJECTED)
        ->and($review->reason)->toBe($reason);
});

test('no transition out of registered succeeds, for any actor', function () {
    $author = reviewAuthorUser();
    $admin = reviewAdmin();
    $oeuvre = reviewOeuvre($author, OeuvreStatus::REGISTERED, holder: $admin);
    $machine = app(OeuvreStatusMachine::class);

    foreach ([OeuvreStatus::DRAFT, OeuvreStatus::SUBMITTED, OeuvreStatus::UNDER_REVIEW, OeuvreStatus::REJECTED] as $target) {
        foreach ([$author, $admin] as $actor) {
            expect(fn () => $machine->transition($oeuvre, $target, $actor, 'any reason', takeOver: true))
                ->toThrow(IllegalTransition::class);
        }
    }

    // Including through the HTTP endpoints, where the take-over flag exists.
    $this->actingAs($admin)
        ->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => 'changed my mind', 'take_over' => true])
        ->assertInvalid('status');

    $this->actingAs($admin)
        ->post(route('admin.oeuvres.review', $oeuvre), ['take_over' => true])
        ->assertInvalid('status');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REGISTERED);
});

test('a registered deposit cannot be resubmitted by its author', function () {
    $author = reviewAuthorUser();
    $oeuvre = reviewOeuvre($author, OeuvreStatus::REGISTERED);

    $this->actingAs($author)
        ->post(route('author.oeuvres.submit', $oeuvre))
        ->assertStatus(Response::HTTP_FORBIDDEN);

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REGISTERED);
});

test('an author cannot approve their own deposit', function () {
    $author = reviewAuthorUser();
    $oeuvre = reviewOeuvre($author, OeuvreStatus::UNDER_REVIEW);

    $this->actingAs($author)
        ->post(route('admin.oeuvres.approve', $oeuvre))
        ->assertStatus(Response::HTTP_FORBIDDEN);

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::UNDER_REVIEW);
});

test('the machine refuses an admin transition even without the route middleware', function () {
    $author = reviewAuthorUser();
    $oeuvre = reviewOeuvre($author, OeuvreStatus::SUBMITTED);
    $machine = app(OeuvreStatusMachine::class);

    // The role gate lives on the route, but the transition table's actor
    // column is enforced here too — a future route that forgets the
    // middleware still cannot register a deposit.
    expect(fn () => $machine->transition($oeuvre, OeuvreStatus::UNDER_REVIEW, $author))
        ->toThrow(IllegalTransition::class);
});

test('a rejected deposit can be resubmitted, producing a second review row', function () {
    $author = reviewAuthorUser();
    $admin = reviewAdmin();
    $oeuvre = reviewOeuvre($author, OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => 'Scan illisible.']);

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REJECTED);

    $this->actingAs($author)
        ->post(route('author.oeuvres.submit', $oeuvre))
        ->assertRedirect(route('oeuvres.show', $oeuvre));

    $reviews = OeuvreReview::where('oeuvre_id', $oeuvre->id)->orderBy('id')->get();

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::SUBMITTED)
        ->and($reviews)->toHaveCount(2)
        ->and($reviews[1]->from_status)->toBe(OeuvreStatus::REJECTED)
        ->and($reviews[1]->to_status)->toBe(OeuvreStatus::SUBMITTED)
        // Resubmission clears the previous round's holder: the deposit is
        // back in the queue, not still on someone's desk.
        ->and($oeuvre->fresh()->reviewed_by)->toBeNull();
});

test('the history survives: an approved resubmission keeps every earlier row', function () {
    $author = reviewAuthorUser();
    $admin = reviewAdmin();
    $oeuvre = reviewOeuvre($author, OeuvreStatus::UNDER_REVIEW, holder: $admin);

    $this->actingAs($admin)->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => 'Scan illisible.']);
    $this->actingAs($author)->post(route('author.oeuvres.submit', $oeuvre));
    $this->actingAs($admin)->post(route('admin.oeuvres.review', $oeuvre));
    $this->actingAs($admin)->post(route('admin.oeuvres.approve', $oeuvre));

    $trail = OeuvreReview::where('oeuvre_id', $oeuvre->id)->orderBy('id')->get()
        ->map(fn (OeuvreReview $r): string => "{$r->from_status}->{$r->to_status}")
        ->all();

    expect($trail)->toBe([
        'under_review->rejected',
        'rejected->submitted',
        'submitted->under_review',
        'under_review->registered',
    ]);
});
