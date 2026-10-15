<?php

declare(strict_types=1);

use App\Domain\Deposit\MediaFileStatus;
use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * `oeuvres.files.status`: what the author's oeuvre page polls so its
 * progress rail follows the server pipeline after `complete`, live.
 */
function statusAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('author');

    return $user;
}

function statusFile(Oeuvre $oeuvre, string $status, array $attributes = []): MediaFile
{
    return MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'uploaded_by' => $oeuvre->author_id,
        'status' => $status,
        ...$attributes,
    ]);
}

it('reports the status, rail stage and error of every pipeline state', function () {
    $author = statusAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);

    $expected = [
        MediaFileStatus::UPLOADING => ['upload', null],
        MediaFileStatus::ASSEMBLING => ['assembly', null],
        MediaFileStatus::SCANNING => ['antivirus', null],
        MediaFileStatus::PROCESSING => ['processing', null],
        MediaFileStatus::READY => ['sealed', null],
        MediaFileStatus::FAILED => [null, 'media.failedWithRef'],
        MediaFileStatus::QUARANTINED => [null, 'media.quarantineWithRef'],
    ];

    $files = [];
    $minute = 0;
    foreach (array_keys($expected) as $status) {
        $files[$status] = statusFile($oeuvre, $status, ['created_at' => now()->addMinutes($minute++)]);
    }

    $response = $this->actingAs($author)
        ->getJson(route('oeuvres.files.status', $oeuvre))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    $rows = $response->json('files');
    expect($rows)->toHaveCount(count($expected));

    foreach (array_values($expected) as $i => [$stage, $error]) {
        $status = array_keys($expected)[$i];

        expect($rows[$i])->toBe([
            'id' => $files[$status]->uuid,
            'status' => $status,
            'stage' => $stage,
            'error' => $error,
        ]);
    }
});

it('follows a file from scanning to ready between two polls', function () {
    $author = statusAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);
    $file = statusFile($oeuvre, MediaFileStatus::SCANNING);
    $url = route('oeuvres.files.status', $oeuvre);

    $this->actingAs($author)->getJson($url)
        ->assertJsonPath('files.0.status', 'scanning')
        ->assertJsonPath('files.0.stage', 'antivirus');

    $file->forceFill(['status' => MediaFileStatus::READY])->save();

    $this->actingAs($author)->getJson($url)
        ->assertJsonPath('files.0.status', 'ready')
        ->assertJsonPath('files.0.stage', 'sealed');
});

it('leaves out removed files and exposes no numeric id', function () {
    $author = statusAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);
    $kept = statusFile($oeuvre, MediaFileStatus::READY);
    statusFile($oeuvre, MediaFileStatus::FAILED)->delete();

    $response = $this->actingAs($author)
        ->getJson(route('oeuvres.files.status', $oeuvre))
        ->assertOk()
        ->assertJsonCount(1, 'files')
        ->assertJsonPath('files.0.id', $kept->uuid);

    expect(array_keys($response->json('files.0')))->toBe(['id', 'status', 'stage', 'error']);
});

it('answers 404 to another author, whatever the oeuvre holds', function () {
    $owner = statusAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $owner->id]);
    statusFile($oeuvre, MediaFileStatus::SCANNING);

    $this->actingAs(statusAuthor())
        ->getJson(route('oeuvres.files.status', $oeuvre))
        ->assertNotFound()
        ->assertJsonMissing(['status' => 'scanning']);
});

it('answers 404 for an unknown oeuvre', function () {
    $this->actingAs(statusAuthor())
        ->getJson('/author/oeuvres/01a00000-0000-7000-8000-000000000000/files/status')
        ->assertNotFound();
});

it('is closed to guests and to admins', function () {
    $owner = statusAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $owner->id]);
    $url = route('oeuvres.files.status', $oeuvre);

    $this->getJson($url)->assertUnauthorized();
    $this->get($url)->assertRedirect(route('login'));

    Role::findOrCreate('admin');
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole('admin');

    $this->actingAs($admin)->getJson($url)->assertForbidden();
});

it('reads the files in one query, however many there are', function (int $count) {
    $author = statusAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id]);
    for ($i = 0; $i < $count; $i++) {
        statusFile($oeuvre, $i % 2 === 0 ? MediaFileStatus::SCANNING : MediaFileStatus::READY);
    }
    $url = route('oeuvres.files.status', $oeuvre);

    // Warm the session and the role cache, as a poll after the first one is.
    $this->actingAs($author)->getJson($url)->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($author)->getJson($url)->assertOk()->assertJsonCount($count, 'files');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'media_files'))->count())->toBe(1);
    expect($queries->count())->toBeLessThanOrEqual(6);
})->with([1, 25]);
