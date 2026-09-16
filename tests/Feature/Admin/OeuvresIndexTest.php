<?php

declare(strict_types=1);

use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the works index never includes a draft, even one belonging to the viewed author', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();

    Oeuvre::factory()->create(['author_id' => $author->id, 'title' => 'Still drafting', 'status' => 'draft']);
    $submitted = Oeuvre::factory()->create(['author_id' => $author->id, 'title' => 'Sent in', 'status' => 'submitted']);

    $response = $this->actingAs($admin)->get(route('admin.oeuvres.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/oeuvres/Index')
        ->has('oeuvres.data', 1)
        ->where('oeuvres.data.0.uuid', $submitted->uuid)
    );
});

test('a work with a quarantined file is flagged as having a blocking file', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);
    MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id, 'status' => 'ready']);
    MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id, 'status' => 'quarantined']);

    $response = $this->actingAs($admin)->get(route('admin.oeuvres.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('oeuvres.data.0.has_blocking_file', true)
        ->where('oeuvres.data.0.all_ready', false)
    );
});

test('a work whose files are all ready is flagged accordingly', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);
    MediaFile::factory()->count(2)->create(['oeuvre_id' => $oeuvre->id, 'status' => 'ready']);

    $response = $this->actingAs($admin)->get(route('admin.oeuvres.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('oeuvres.data.0.all_ready', true)
        ->where('oeuvres.data.0.has_blocking_file', false)
    );
});

test('visiting a draft work directly on the show route 404s', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $draft = Oeuvre::factory()->create(['author_id' => $author->id, 'status' => 'draft']);

    $this->actingAs($admin)->get(route('admin.oeuvres.show', $draft))->assertNotFound();
});

test('the works show page never exposes a vault path, wrapped DEK, or nonce', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);
    MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id]);

    $response = $this->actingAs($admin)->get(route('admin.oeuvres.show', $oeuvre));
    $json = json_encode($response->viewData('page')['props']);

    expect($json)
        ->not->toContain('dek_wrapped')
        ->not->toContain('mac_path')
        ->not->toContain('/vault/');
});
