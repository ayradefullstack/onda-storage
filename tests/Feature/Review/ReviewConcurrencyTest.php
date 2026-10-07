<?php

declare(strict_types=1);

use App\Domain\Deposit\Exceptions\IllegalTransition;
use App\Domain\Deposit\Exceptions\ReviewConflict;
use App\Domain\Deposit\OeuvreStatus;
use App\Domain\Deposit\OeuvreStatusMachine;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\OeuvreReview;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * The failure this guards against: two officers open the same deposit and
 * reach opposite decisions a minute apart, the second silently
 * overwriting the first.
 *
 * See OeuvreStatusMachine's docblock for why the holder model was chosen
 * over optimistic locking.
 */
function concurrencyAdmin(string $name): User
{
    Role::findOrCreate('admin');
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    $user->assignRole('admin');

    return $user;
}

function concurrencyOeuvre(?User $holder = null): Oeuvre
{
    Role::findOrCreate('author');
    $author = User::factory()->create(['email_verified_at' => now()]);
    $author->assignRole('author');

    $oeuvre = Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $holder === null ? OeuvreStatus::SUBMITTED : OeuvreStatus::UNDER_REVIEW,
        'submitted_at' => now(),
        'reviewed_by' => $holder?->id,
        'reviewed_at' => $holder === null ? null : now(),
    ]);

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'status' => 'ready',
    ]);

    return $oeuvre;
}

test('a second officer cannot decide on a deposit another officer holds', function () {
    $amina = concurrencyAdmin('Amina');
    $karim = concurrencyAdmin('Karim');
    $oeuvre = concurrencyOeuvre(holder: $amina);

    // The exact double-decision case: Amina holds it, Karim rejects.
    $this->actingAs($karim)
        ->post(route('admin.oeuvres.reject', $oeuvre), ['reason' => 'Dossier incomplet.'])
        ->assertStatus(Response::HTTP_CONFLICT);

    // And the opposite decision is refused just the same.
    $this->actingAs($karim)
        ->post(route('admin.oeuvres.approve', $oeuvre))
        ->assertStatus(Response::HTTP_CONFLICT);

    $oeuvre->refresh();

    expect($oeuvre->status)->toBe(OeuvreStatus::UNDER_REVIEW)
        ->and($oeuvre->reviewed_by)->toBe($amina->id)
        ->and(OeuvreReview::where('oeuvre_id', $oeuvre->id)->count())->toBe(0);
});

test('the conflict names the officer who holds it', function () {
    $amina = concurrencyAdmin('Amina');
    $karim = concurrencyAdmin('Karim');
    $oeuvre = concurrencyOeuvre(holder: $amina);

    expect(fn () => app(OeuvreStatusMachine::class)
        ->transition($oeuvre, OeuvreStatus::REGISTERED, $karim))
        ->toThrow(ReviewConflict::class, 'Amina is already reviewing this deposit. Take it over explicitly to decide on it.');
});

test('opening a deposit another officer holds is refused without an explicit take-over', function () {
    $amina = concurrencyAdmin('Amina');
    $karim = concurrencyAdmin('Karim');
    $oeuvre = concurrencyOeuvre(holder: $amina);

    $this->actingAs($karim)
        ->post(route('admin.oeuvres.review', $oeuvre))
        ->assertStatus(Response::HTTP_CONFLICT);

    expect($oeuvre->fresh()->reviewed_by)->toBe($amina->id);
});

test('an explicit take-over reassigns the holder without writing a decision row', function () {
    $amina = concurrencyAdmin('Amina');
    $karim = concurrencyAdmin('Karim');
    $oeuvre = concurrencyOeuvre(holder: $amina);

    $this->actingAs($karim)
        ->post(route('admin.oeuvres.review', $oeuvre), ['take_over' => true])
        ->assertRedirect();

    $oeuvre->refresh();

    expect($oeuvre->reviewed_by)->toBe($karim->id)
        ->and($oeuvre->status)->toBe(OeuvreStatus::UNDER_REVIEW)
        // A take-over is not a status change, so it records no decision.
        // Who actually decided is the actor_id of the decision that follows.
        ->and(OeuvreReview::where('oeuvre_id', $oeuvre->id)->count())->toBe(0);

    $this->actingAs($karim)
        ->post(route('admin.oeuvres.approve', $oeuvre))
        ->assertRedirect();

    $review = OeuvreReview::where('oeuvre_id', $oeuvre->id)->latest('id')->first();

    expect($review->actor_id)->toBe($karim->id);
});

test('re-opening a deposit you already hold is a no-op, not a conflict', function () {
    $amina = concurrencyAdmin('Amina');
    $oeuvre = concurrencyOeuvre(holder: $amina);

    $this->actingAs($amina)
        ->post(route('admin.oeuvres.review', $oeuvre))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($oeuvre->fresh()->reviewed_by)->toBe($amina->id)
        ->and(OeuvreReview::where('oeuvre_id', $oeuvre->id)->count())->toBe(0);
});

test('an unheld under_review deposit accepts a decision from any officer', function () {
    $karim = concurrencyAdmin('Karim');
    // reviewed_by null: the deposit was left open by an officer whose
    // account has since been removed, or by data predating this column.
    $oeuvre = concurrencyOeuvre();
    $oeuvre->forceFill(['status' => OeuvreStatus::UNDER_REVIEW, 'reviewed_by' => null])->save();

    $this->actingAs($karim)
        ->post(route('admin.oeuvres.approve', $oeuvre))
        ->assertRedirect();

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REGISTERED);
});

test('a decision refuses once the status has moved underneath it', function () {
    $amina = concurrencyAdmin('Amina');
    $oeuvre = concurrencyOeuvre(holder: $amina);
    $machine = app(OeuvreStatusMachine::class);

    // Amina approves. The stale instance Karim's request is holding still
    // says `under_review`, and the lock-and-recheck is what catches it.
    $machine->transition($oeuvre->fresh(), OeuvreStatus::REGISTERED, $amina);

    expect(fn () => $machine->transition($oeuvre, OeuvreStatus::REJECTED, $amina, 'too late'))
        ->toThrow(IllegalTransition::class);

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::REGISTERED)
        ->and(OeuvreReview::where('oeuvre_id', $oeuvre->id)->count())->toBe(1);
});
