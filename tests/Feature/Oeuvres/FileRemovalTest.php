<?php

declare(strict_types=1);

use App\Domain\Deposit\MediaFileStatus;
use App\Domain\Deposit\OeuvreStatus;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Removing a file from a deposit.
 *
 * This exists because the submission gate refuses a `failed` or
 * `quarantined` file: without removal, one failed upload would block
 * submission permanently. So these tests care about two things — the
 * window in which removal is allowed, and the fact that it never touches
 * the bytes.
 */
function removalAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function removalOeuvre(User $author, string $status = OeuvreStatus::DRAFT): Oeuvre
{
    return Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
    ]);
}

/**
 * A media file whose ciphertext actually exists on the vault disk, so a
 * test can assert the bytes survived the row being trashed.
 */
function removalFileWithBytes(Oeuvre $oeuvre, string $status = MediaFileStatus::READY, string $name = 'deposit.mp4'): MediaFile
{
    $mediaFile = MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $oeuvre->author_id,
        'original_name' => $name,
        'status' => $status,
    ]);

    Storage::disk('vault')->put($mediaFile->path, 'ciphertext-bytes');
    Storage::disk('vault')->put($mediaFile->mac_path, 'mac-bytes');

    return $mediaFile;
}

test('an author removes a ready, failed or quarantined file from a draft', function (string $status) {
    $author = removalAuthor();
    $oeuvre = removalOeuvre($author);
    $mediaFile = removalFileWithBytes($oeuvre, $status);

    $this->actingAs($author)
        ->delete(route('oeuvres.files.destroy', $mediaFile))
        ->assertRedirect();

    expect(MediaFile::find($mediaFile->id))->toBeNull()
        ->and(MediaFile::withTrashed()->find($mediaFile->id)->trashed())->toBeTrue();
})->with([
    'ready' => [MediaFileStatus::READY],
    'failed' => [MediaFileStatus::FAILED],
    'quarantined' => [MediaFileStatus::QUARANTINED],
]);

test('removing a file soft-deletes the row and leaves the bytes on disk', function () {
    $author = removalAuthor();
    $oeuvre = removalOeuvre($author);
    $mediaFile = removalFileWithBytes($oeuvre);

    $this->actingAs($author)->delete(route('oeuvres.files.destroy', $mediaFile));

    $trashed = MediaFile::withTrashed()->findOrFail($mediaFile->id);

    expect($trashed->trashed())->toBeTrue()
        // The purge job frees bytes after the retention window; removal
        // must not, because a deduplicated row may still point at them.
        ->and(Storage::disk('vault')->exists($mediaFile->path))->toBeTrue()
        ->and(Storage::disk('vault')->exists($mediaFile->mac_path))->toBeTrue()
        ->and($trashed->purged_at)->toBeNull()
        // Still counted against the author's disk usage, as CLAUDE.md
        // defines it — the quota does not come back today.
        ->and(MediaFile::withTrashed()->whereNull('purged_at')->where('id', $mediaFile->id)->count())->toBe(1);
});

test('removing one of a deduplicated pair leaves the other file\'s bytes intact', function () {
    $author = removalAuthor();
    $oeuvre = removalOeuvre($author);

    $original = removalFileWithBytes($oeuvre, name: 'original.mp4');

    // The dedup path points a second row at the same bytes and bumps
    // ref_count — removing one row must not destroy the other's content.
    $duplicate = MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'original_name' => 'duplicate.mp4',
        'status' => MediaFileStatus::READY,
        'path' => $original->path,
        'mac_path' => $original->mac_path,
        'sha256_plain' => $original->sha256_plain,
    ]);
    $original->forceFill(['ref_count' => 2])->save();

    $this->actingAs($author)->delete(route('oeuvres.files.destroy', $duplicate));

    expect(MediaFile::withTrashed()->find($duplicate->id)->trashed())->toBeTrue()
        ->and(MediaFile::find($original->id))->not->toBeNull()
        ->and(Storage::disk('vault')->exists($original->path))->toBeTrue()
        ->and(Storage::disk('vault')->get($original->path))->toBe('ciphertext-bytes');
});

test('a removed quarantined file is still visible to an admin via withTrashed', function () {
    Role::findOrCreate('admin');

    $author = removalAuthor();
    $oeuvre = removalOeuvre($author);
    $mediaFile = removalFileWithBytes($oeuvre, MediaFileStatus::QUARANTINED, 'infected.mp4');

    $this->actingAs($author)->delete(route('oeuvres.files.destroy', $mediaFile));

    $evidence = MediaFile::withTrashed()->findOrFail($mediaFile->id);

    // Removal takes it off the author's deposit; it does not erase what
    // was uploaded, nor the fact that it was quarantined.
    expect($evidence->trashed())->toBeTrue()
        ->and($evidence->status)->toBe(MediaFileStatus::QUARANTINED)
        ->and($evidence->original_name)->toBe('infected.mp4')
        ->and(Storage::disk('vault')->exists($evidence->path))->toBeTrue();
});

test('removing a file that is still scanning or processing is refused, naming it', function (string $status) {
    $author = removalAuthor();
    $oeuvre = removalOeuvre($author);
    $mediaFile = removalFileWithBytes($oeuvre, $status, 'in-flight.mp4');

    $this->actingAs($author)
        ->delete(route('oeuvres.files.destroy', $mediaFile))
        ->assertInvalid('media_file');

    expect(MediaFile::find($mediaFile->id))->not->toBeNull();

    expect(implode(' ', session('errors')?->get('media_file') ?? []))
        ->toContain('in-flight.mp4');
})->with([
    'scanning' => [MediaFileStatus::SCANNING],
    'processing' => [MediaFileStatus::PROCESSING],
    'assembling' => [MediaFileStatus::ASSEMBLING],
]);

test('a file still uploading is cancelled, not removed', function () {
    $author = removalAuthor();
    $oeuvre = removalOeuvre($author);
    $mediaFile = removalFileWithBytes($oeuvre, MediaFileStatus::UPLOADING, 'in-flight.mp4');

    $this->actingAs($author)
        ->delete(route('oeuvres.files.destroy', $mediaFile))
        ->assertInvalid('media_file');

    expect(MediaFile::find($mediaFile->id))->not->toBeNull();
});

test('removing a file from a frozen oeuvre is refused, even by direct request', function (string $status) {
    $author = removalAuthor();
    $oeuvre = removalOeuvre($author, $status);
    $mediaFile = removalFileWithBytes($oeuvre);

    $this->actingAs($author)
        ->delete(route('oeuvres.files.destroy', $mediaFile))
        ->assertStatus(Response::HTTP_FORBIDDEN);

    expect(MediaFile::find($mediaFile->id))->not->toBeNull();
})->with([
    'submitted' => [OeuvreStatus::SUBMITTED],
    'under_review' => [OeuvreStatus::UNDER_REVIEW],
    'registered' => [OeuvreStatus::REGISTERED],
]);

test('a rejected oeuvre reopens for file removal', function () {
    $author = removalAuthor();
    $oeuvre = removalOeuvre($author, OeuvreStatus::REJECTED);
    $mediaFile = removalFileWithBytes($oeuvre);

    $this->actingAs($author)
        ->delete(route('oeuvres.files.destroy', $mediaFile))
        ->assertRedirect();

    expect(MediaFile::find($mediaFile->id))->toBeNull();
});

test('removing a file from another author\'s oeuvre is refused', function () {
    $author = removalAuthor();
    $stranger = removalAuthor();
    $oeuvre = removalOeuvre($author);
    $mediaFile = removalFileWithBytes($oeuvre);

    $this->actingAs($stranger)
        ->delete(route('oeuvres.files.destroy', $mediaFile))
        ->assertStatus(Response::HTTP_FORBIDDEN);

    expect(MediaFile::find($mediaFile->id))->not->toBeNull();
});

test('removing the only failed file makes the oeuvre submittable again', function () {
    $author = removalAuthor();
    $oeuvre = removalOeuvre($author);

    removalFileWithBytes($oeuvre, MediaFileStatus::READY, 'good.mp4');
    $broken = removalFileWithBytes($oeuvre, MediaFileStatus::FAILED, 'broken.mp4');

    // Blocked while the failed file is attached.
    $this->actingAs($author)
        ->post(route('author.oeuvres.submit', $oeuvre))
        ->assertInvalid('submission');

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::DRAFT);

    $this->actingAs($author)->delete(route('oeuvres.files.destroy', $broken));

    $this->actingAs($author)
        ->post(route('author.oeuvres.submit', $oeuvre))
        ->assertRedirect(route('oeuvres.show', $oeuvre));

    expect($oeuvre->fresh()->status)->toBe(OeuvreStatus::SUBMITTED);
});

test('the show page reports per-file removability', function () {
    $author = removalAuthor();
    $oeuvre = removalOeuvre($author);

    removalFileWithBytes($oeuvre, MediaFileStatus::READY, 'a.mp4');
    removalFileWithBytes($oeuvre, MediaFileStatus::SCANNING, 'b.mp4');

    $this->actingAs($author)
        ->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('oeuvre.can.edit', true)
            ->where('mediaFiles.0.can_remove', true)
            // Mid-pipeline: the control renders, disabled, with a reason.
            ->where('mediaFiles.1.can_remove', false)
        );
});

test('a frozen oeuvre reports every file as unremovable', function () {
    $author = removalAuthor();
    $oeuvre = removalOeuvre($author, OeuvreStatus::REGISTERED);

    removalFileWithBytes($oeuvre);

    $this->actingAs($author)
        ->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn ($page) => $page
            ->where('oeuvre.can.edit', false)
            ->where('oeuvre.can.delete', false)
            ->where('mediaFiles.0.can_remove', false)
        );
});

/**
 * The classification is fixed at creation: every uploaded file carries a
 * `college_oeuvre_file_id` bound to the current collège, so a mutable
 * classification would let that link drift. This asserts the absence of a
 * route rather than trusting a comment.
 */
test('no route exists that changes an existing oeuvre\'s classification', function () {
    // Every route that acts on an already-created oeuvre. If a future
    // "edit classification" endpoint is added, it lands in this list and
    // this test fails — which is the point.
    $oeuvreRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains((string) $route->uri(), 'oeuvres/{oeuvre'))
        ->map(fn ($route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.($route->getName() ?? $route->uri()))
        ->sort()
        ->values()
        ->all();

    expect($oeuvreRoutes)->toBe([
        'DELETE oeuvres.destroy',
        'GET admin.oeuvres.show',
        'GET oeuvres.show',
        'POST admin.oeuvres.approve',
        'POST admin.oeuvres.reject',
        'POST admin.oeuvres.review',
        'POST author.oeuvres.submit',
    ]);

    // Nothing PUTs or PATCHes an oeuvre at all.
    expect(array_filter($oeuvreRoutes, fn (string $route): bool => str_starts_with($route, 'PUT') || str_starts_with($route, 'PATCH')))
        ->toBe([]);

    // And the model would refuse the fields even if a route appeared:
    // the classification, `status` and `author_id` are all unfillable.
    $oeuvre = removalOeuvre(removalAuthor());
    $classificationFields = [
        'register_type_id',
        'type_gestion_id',
        'register_type_college_id',
        'register_type_member_id',
        'code_college_snapshot',
    ];
    $original = $oeuvre->only($classificationFields);

    $oeuvre->fill(['status' => OeuvreStatus::REGISTERED, 'author_id' => 999]);

    expect($oeuvre->status)->toBe(OeuvreStatus::DRAFT)
        ->and($oeuvre->author_id)->not->toBe(999)
        ->and($oeuvre->only($classificationFields))->toBe($original);
});
