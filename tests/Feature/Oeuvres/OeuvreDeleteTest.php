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
 * Deleting a whole deposit.
 *
 * Two properties matter beyond "it works": the files go with it (a DB
 * cascade only fires on a hard delete, so soft-deleting the oeuvre alone
 * would orphan every `media_files` row), and the vault bytes do not.
 */
function deleteAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function deleteOeuvreWithFiles(User $author, string $status = OeuvreStatus::DRAFT, string $fileStatus = MediaFileStatus::READY): Oeuvre
{
    $oeuvre = Oeuvre::factory()->create([
        'author_id' => $author->id,
        'status' => $status,
        'submitted_at' => $status === OeuvreStatus::DRAFT ? null : now(),
    ]);

    foreach (['first.mp4', 'second.pdf'] as $name) {
        $mediaFile = MediaFile::factory()->create([
            'oeuvre_id' => $oeuvre->id,
            'uploaded_by' => $author->id,
            'original_name' => $name,
            'status' => $fileStatus,
        ]);

        Storage::disk('vault')->put($mediaFile->path, 'ciphertext-bytes');
        Storage::disk('vault')->put($mediaFile->mac_path, 'mac-bytes');
    }

    return $oeuvre;
}

test('an author deletes a draft or a rejected oeuvre', function (string $status) {
    $author = deleteAuthor();
    $oeuvre = deleteOeuvreWithFiles($author, $status);

    $this->actingAs($author)
        ->delete(route('oeuvres.destroy', $oeuvre))
        ->assertRedirect(route('oeuvres.index'));

    expect(Oeuvre::find($oeuvre->id))->toBeNull()
        ->and(Oeuvre::withTrashed()->find($oeuvre->id)->trashed())->toBeTrue();
})->with([
    'draft' => [OeuvreStatus::DRAFT],
    'rejected' => [OeuvreStatus::REJECTED],
]);

test('deleting an oeuvre soft-deletes its media files too', function () {
    $author = deleteAuthor();
    $oeuvre = deleteOeuvreWithFiles($author);

    $fileIds = $oeuvre->mediaFiles()->pluck('id');
    expect($fileIds)->toHaveCount(2);

    $this->actingAs($author)->delete(route('oeuvres.destroy', $oeuvre));

    // The DB foreign key is cascadeOnDelete, which only fires on a HARD
    // delete — without DeleteOeuvre's explicit loop these rows would stay
    // live under a trashed parent.
    expect(MediaFile::whereIn('id', $fileIds)->count())->toBe(0)
        ->and(MediaFile::withTrashed()->whereIn('id', $fileIds)->count())->toBe(2);
});

test('deleting an oeuvre leaves every vault byte on disk', function () {
    $author = deleteAuthor();
    $oeuvre = deleteOeuvreWithFiles($author);

    $files = $oeuvre->mediaFiles()->get(['id', 'path', 'mac_path', 'size_bytes']);

    $this->actingAs($author)->delete(route('oeuvres.destroy', $oeuvre));

    foreach ($files as $file) {
        expect(Storage::disk('vault')->exists($file->path))->toBeTrue()
            ->and(Storage::disk('vault')->exists($file->mac_path))->toBeTrue();
    }

    // And they still count against disk usage as CLAUDE.md defines it,
    // which is exactly why the dialog says the quota does not recover.
    $stillCharged = MediaFile::withTrashed()
        ->whereIn('id', $files->pluck('id'))
        ->whereNull('purged_at')
        ->sum('size_bytes');

    expect($stillCharged)->toBe((int) $files->sum('size_bytes'));
});

test('deleting a submitted, under_review or registered oeuvre is refused by direct request', function (string $status) {
    $author = deleteAuthor();
    $oeuvre = deleteOeuvreWithFiles($author, $status);

    $this->actingAs($author)
        ->delete(route('oeuvres.destroy', $oeuvre))
        ->assertStatus(Response::HTTP_FORBIDDEN);

    expect(Oeuvre::find($oeuvre->id))->not->toBeNull()
        ->and($oeuvre->mediaFiles()->count())->toBe(2);
})->with([
    'submitted' => [OeuvreStatus::SUBMITTED],
    'under_review' => [OeuvreStatus::UNDER_REVIEW],
    'registered' => [OeuvreStatus::REGISTERED],
]);

test('deleting is refused while any file is mid-pipeline, naming it', function (string $fileStatus) {
    $author = deleteAuthor();
    $oeuvre = deleteOeuvreWithFiles($author, fileStatus: $fileStatus);

    $this->actingAs($author)
        ->delete(route('oeuvres.destroy', $oeuvre))
        ->assertInvalid('oeuvre');

    expect(Oeuvre::find($oeuvre->id))->not->toBeNull()
        ->and($oeuvre->mediaFiles()->count())->toBe(2);

    expect(implode(' ', session('errors')?->get('oeuvre') ?? []))
        ->toContain('first.mp4');
})->with([
    'scanning' => [MediaFileStatus::SCANNING],
    'processing' => [MediaFileStatus::PROCESSING],
    'uploading' => [MediaFileStatus::UPLOADING],
]);

test('one mid-pipeline file blocks the delete even when the rest are ready', function () {
    $author = deleteAuthor();
    $oeuvre = deleteOeuvreWithFiles($author);

    MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $author->id,
        'original_name' => 'still-scanning.mp4',
        'status' => MediaFileStatus::SCANNING,
    ]);

    $this->actingAs($author)
        ->delete(route('oeuvres.destroy', $oeuvre))
        ->assertInvalid('oeuvre');

    expect(Oeuvre::find($oeuvre->id))->not->toBeNull();
    expect(implode(' ', session('errors')?->get('oeuvre') ?? []))
        ->toContain('still-scanning.mp4');
});

test('deleting another author\'s oeuvre is refused', function () {
    $author = deleteAuthor();
    $stranger = deleteAuthor();
    $oeuvre = deleteOeuvreWithFiles($author);

    $this->actingAs($stranger)
        ->delete(route('oeuvres.destroy', $oeuvre))
        ->assertStatus(Response::HTTP_FORBIDDEN);

    expect(Oeuvre::find($oeuvre->id))->not->toBeNull();
});

test('a deleted oeuvre leaves the author\'s table', function () {
    $author = deleteAuthor();
    $kept = deleteOeuvreWithFiles($author);
    $removed = deleteOeuvreWithFiles($author);

    $this->actingAs($author)->delete(route('oeuvres.destroy', $removed));

    $this->actingAs($author)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn ($page) => $page
            ->has('oeuvres.data', 1)
            ->where('oeuvres.data.0.uuid', $kept->uuid)
            ->where('counts.all', 1)
        );
});
