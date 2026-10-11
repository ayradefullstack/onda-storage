<?php

declare(strict_types=1);

use App\Models\FileAccessLog;
use App\Models\Oeuvre;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    $this->admin = consultAdmin();
    $this->author = consultAuthor();
    $this->cleanup = [];
});

afterEach(function () {
    consultCleanup(...$this->cleanup);
});

function consultDeposited(object $test, ?Oeuvre $oeuvre = null, string $name = 'notes.txt', string $body = 'hello review', string $mime = 'text/plain'): array
{
    $oeuvre ??= Oeuvre::factory()->create(['author_id' => $test->author->id]);
    $file = consultFile($test->author, $oeuvre, $body, $name, $mime);
    $test->cleanup[] = $file;

    return [$oeuvre, $file];
}

test('an admin opens the review page for a ready consultation', function () {
    [$oeuvre, $file] = consultDeposited($this);
    consultGenerate($file);

    $this->actingAs($this->admin)
        ->get(route('admin.oeuvres.files.review', [$oeuvre, $file]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/oeuvres/FileReview')
            ->where('file.uuid', $file->uuid)
            ->where('consultation.status', 'ready')
            ->where('consultation.family', 'text')
            ->where('consultation.actions.download', false)
            ->has('consultation.assets', 1)
            ->has('siblings', 1));
});

test('the review page shows every oeuvre the admin oeuvre page shows, whatever its status', function (string $state) {
    $factory = Oeuvre::factory();
    $oeuvre = ($state === 'draft' ? $factory : $factory->$state())->create(['author_id' => $this->author->id]);
    [, $file] = consultDeposited($this, $oeuvre);

    $this->actingAs($this->admin)->get(route('admin.oeuvres.show', $oeuvre))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$oeuvre, $file]))->assertOk();
})->with(['draft', 'submitted', 'underReview', 'rejected', 'registered']);

test('a media file of another oeuvre under this oeuvre is a 404 on every consultation route', function () {
    [$oeuvreA] = consultDeposited($this);
    [, $fileB] = consultDeposited($this);

    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$oeuvreA, $fileB]))->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review.assets', [$oeuvreA, $fileB]))->assertNotFound();
});

test('non-admins are refused', function () {
    [$oeuvre, $file] = consultDeposited($this);

    $this->actingAs($this->author)->get(route('admin.oeuvres.files.review', [$oeuvre, $file]))->assertForbidden();
    $this->actingAs($this->author)->getJson(route('admin.oeuvres.files.review.assets', [$oeuvre, $file]))->assertForbidden();
});

test('a guest is sent to login', function () {
    [$oeuvre, $file] = consultDeposited($this);

    $this->get(route('admin.oeuvres.files.review', [$oeuvre, $file]))->assertRedirect();
});

test('an unsupported file exposes no url at all', function () {
    [$oeuvre, $file] = consultDeposited($this, name: 'bundle.rar', body: 'Rar!not really', mime: 'application/vnd.rar');
    consultGenerate($file);

    $json = $this->actingAs($this->admin)
        ->getJson(route('admin.oeuvres.files.review.assets', [$oeuvre, $file]))
        ->assertOk()
        ->json('consultation');

    expect($json['status'])->toBe('unsupported')
        ->and($json['assets'])->toBe([])
        ->and($json['reason'])->toBe('unsupported_format')
        ->and(json_encode($json))->not->toContain('signature=');
});

test('a file with no consultation yet is pending, with no url', function () {
    [$oeuvre, $file] = consultDeposited($this);

    $json = $this->actingAs($this->admin)
        ->getJson(route('admin.oeuvres.files.review.assets', [$oeuvre, $file]))
        ->json('consultation');

    expect($json['status'])->toBe('pending')
        ->and($json['reason'])->toBe('not_generated')
        ->and($json['assets'])->toBe([]);
});

test('the descriptor never leaks a storage path, the dek or a nonce', function () {
    [$oeuvre, $file] = consultDeposited($this);
    consultGenerate($file);

    $raw = json_encode($this->actingAs($this->admin)
        ->getJson(route('admin.oeuvres.files.review.assets', [$oeuvre, $file]))
        ->json());

    expect($raw)->not->toContain($file->path)
        ->and($raw)->not->toContain($file->dek_wrapped)
        ->and($raw)->not->toContain(consultAssetRow($file, 'text', 0)->nonce)
        ->and($raw)->not->toContain(consultAssetRow($file, 'text', 0)->path);
});

test('opening the review page writes one admin_consult row per file per window', function () {
    [$oeuvre, $file] = consultDeposited($this);
    consultGenerate($file);

    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$oeuvre, $file]))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$oeuvre, $file]))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$oeuvre, $file]))->assertOk();

    $rows = FileAccessLog::where('media_file_id', $file->id)->where('action', 'admin_consult')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->user_id)->toBe($this->admin->id)
        ->and($rows[0]->ip)->not->toBe('')
        ->and($rows[0]->row_hash)->toHaveLength(64);
});

test('issuing descriptors is logged once per file per window, separately from the page open', function () {
    [$oeuvre, $file] = consultDeposited($this);
    consultGenerate($file);

    foreach (range(1, 3) as $_) {
        $this->actingAs($this->admin)->getJson(route('admin.oeuvres.files.review.assets', [$oeuvre, $file]))->assertOk();
    }

    expect(FileAccessLog::where('media_file_id', $file->id)->where('action', 'admin_consult')->count())->toBe(1);

    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$oeuvre, $file]))->assertOk();

    expect(FileAccessLog::where('media_file_id', $file->id)->where('action', 'admin_consult')->count())->toBe(2);
});

test('a second admin and a second file each get their own row', function () {
    [$oeuvre, $file] = consultDeposited($this);
    $second = consultFile($this->author, $oeuvre, 'second', 'second.txt', 'text/plain');
    $this->cleanup[] = $second;
    $otherAdmin = consultAdmin();

    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$oeuvre, $file]))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.oeuvres.files.review', [$oeuvre, $second]))->assertOk();
    $this->actingAs($otherAdmin)->get(route('admin.oeuvres.files.review', [$oeuvre, $file]))->assertOk();

    expect(FileAccessLog::where('action', 'admin_consult')->count())->toBe(3);
});
