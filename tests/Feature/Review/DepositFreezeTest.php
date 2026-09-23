<?php

declare(strict_types=1);

use App\Actions\Upload\InitUpload;
use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\OeuvreStatus;
use App\Jobs\ProcessMediaFile;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Once a deposit leaves `draft` the author can no longer change it — and
 * that has to hold on the server. A frozen oeuvre enforced only by hiding
 * the upload button is not frozen.
 *
 * These go through `InitUpload` directly as well as through the HTTP
 * endpoint: the endpoint is what a browser hits, the action is what any
 * future caller would.
 */
function freezeAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function freezeOeuvre(User $author, string $status): Oeuvre
{
    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function freezePayload(Oeuvre $oeuvre): array
{
    return [
        'oeuvre_id' => $oeuvre->id,
        'filename' => 'deposit.mp4',
        'size_bytes' => 1024,
        'mime' => 'video/mp4',
    ];
}

test('InitUpload refuses an upload into a submitted oeuvre', function () {
    $author = freezeAuthor();
    $oeuvre = freezeOeuvre($author, OeuvreStatus::SUBMITTED);

    expect(fn () => app(InitUpload::class)->handle($author, $oeuvre->id, 'deposit.mp4', 1024, 'video/mp4'))
        ->toThrow(ValidationException::class, 'This work has been submitted and can no longer receive files.');
});

test('the upload endpoint refuses a submitted, under_review or registered oeuvre', function (string $status) {
    $author = freezeAuthor();
    $oeuvre = freezeOeuvre($author, $status);

    $this->actingAs($author)
        ->postJson(route('uploads.init'), freezePayload($oeuvre))
        ->assertInvalid('oeuvre_id');

    expect($oeuvre->uploadSessions()->count())->toBe(0);
})->with([
    'submitted' => [OeuvreStatus::SUBMITTED],
    'under_review' => [OeuvreStatus::UNDER_REVIEW],
    'registered' => [OeuvreStatus::REGISTERED],
]);

test('InitUpload accepts an upload into a rejected oeuvre', function () {
    $author = freezeAuthor();
    $oeuvre = freezeOeuvre($author, OeuvreStatus::REJECTED);

    $session = app(InitUpload::class)->handle($author, $oeuvre->id, 'deposit.mp4', 1024, 'video/mp4');

    expect($session->exists)->toBeTrue()
        ->and($session->oeuvre_id)->toBe($oeuvre->id);
});

test('InitUpload accepts an upload into a draft oeuvre', function () {
    $author = freezeAuthor();
    $oeuvre = freezeOeuvre($author, OeuvreStatus::DRAFT);

    $session = app(InitUpload::class)->handle($author, $oeuvre->id, 'deposit.mp4', 1024, 'video/mp4');

    expect($session->exists)->toBeTrue();
});

test('the freeze is reported as a freeze, not as someone else\'s work', function () {
    $author = freezeAuthor();
    $stranger = freezeAuthor();
    $oeuvre = freezeOeuvre($author, OeuvreStatus::SUBMITTED);

    // Ownership is checked first, so a stranger probing oeuvre ids learns
    // nothing about the work's status.
    $this->actingAs($stranger)
        ->postJson(route('uploads.init'), freezePayload($oeuvre))
        ->assertForbidden();
});

test('the policy denies editing a submitted, under_review or registered oeuvre', function (string $status) {
    $author = freezeAuthor();
    $oeuvre = freezeOeuvre($author, $status);

    expect($author->can('update', $oeuvre))->toBeFalse()
        ->and($author->can('submit', $oeuvre))->toBeFalse()
        // Reading their own deposit is always allowed.
        ->and($author->can('view', $oeuvre))->toBeTrue();
})->with([
    'submitted' => [OeuvreStatus::SUBMITTED],
    'under_review' => [OeuvreStatus::UNDER_REVIEW],
    'registered' => [OeuvreStatus::REGISTERED],
]);

test('the policy reopens a rejected oeuvre', function () {
    $author = freezeAuthor();
    $oeuvre = freezeOeuvre($author, OeuvreStatus::REJECTED);

    expect($author->can('update', $oeuvre))->toBeTrue()
        ->and($author->can('submit', $oeuvre))->toBeTrue();
});

test('the show page reports a frozen oeuvre as closed', function () {
    $author = freezeAuthor();
    $oeuvre = freezeOeuvre($author, OeuvreStatus::UNDER_REVIEW);

    $this->actingAs($author)
        ->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page->where('submission.is_open', false));
});

/**
 * The guard sits at the top of InitUpload, ahead of everything else. This
 * is the proof that it refuses what it should and touches nothing else:
 * a real chunked upload into a draft still runs init -> chunk -> complete
 * and comes out the far side as a media file, with the bytes intact.
 */
test('a full chunked upload into a draft still completes, unaffected by the guard', function () {
    Bus::fake();
    // Small chunks, as in UploadLifecycleTest — must precede the first
    // request, since VaultContract reads the size once in its constructor.
    config(['vault.chunk_size' => 32, 'vault.mac_segment_size' => 16]);

    $author = freezeAuthor();
    $oeuvre = freezeOeuvre($author, OeuvreStatus::DRAFT);

    $plaintext = str_repeat('A', 74);

    $init = $this->actingAs($author)->postJson(route('uploads.init'), [
        'oeuvre_id' => $oeuvre->id,
        'filename' => 'movie.mp4',
        'size_bytes' => strlen($plaintext),
        'mime' => 'video/mp4',
    ])->assertCreated();

    $uuid = $init->json('uuid');
    expect($init->json('total_chunks'))->toBe(3);

    foreach ([0, 1, 2] as $index) {
        $chunk = substr($plaintext, $index * 32, 32);

        $this->call('POST', "/uploads/{$uuid}/chunk/{$index}", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CHUNK_CRC32' => hash('crc32b', $chunk),
        ], $chunk)->assertOk();
    }

    $complete = $this->postJson("/uploads/{$uuid}/complete")->assertCreated();

    $mediaFile = MediaFile::where('uuid', $complete->json('uuid'))->firstOrFail();

    expect($mediaFile->oeuvre_id)->toBe($oeuvre->id)
        ->and($mediaFile->size_bytes)->toBe(74)
        // Handed to the pipeline exactly as before the guard existed.
        ->and($mediaFile->status)->toBe(MediaFileStatus::SCANNING);

    Bus::assertDispatched(ProcessMediaFile::class);
});
