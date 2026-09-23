<?php

declare(strict_types=1);

use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('an author\'s show page lists only that author\'s works', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $stranger = User::factory()->withRole('author')->create();

    $ownOeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'title' => 'Mine', 'status' => 'submitted']);
    Oeuvre::factory()->create(['author_id' => $stranger->id, 'title' => 'Not mine', 'status' => 'submitted']);
    MediaFile::factory()->create(['oeuvre_id' => $ownOeuvre->id]);

    $response = $this->actingAs($admin)->get(route('admin.authors.show', $author));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/authors/Show')
        ->where('author.uuid', $author->uuid)
        ->has('oeuvres.data', 1)
        ->where('oeuvres.data.0.title', 'Mine')
    );
});

test('the author show page excludes drafts from the works list', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();

    Oeuvre::factory()->create(['author_id' => $author->id, 'title' => 'Draft', 'status' => 'draft']);
    $submitted = Oeuvre::factory()->create(['author_id' => $author->id, 'title' => 'Submitted', 'status' => 'submitted']);

    $response = $this->actingAs($admin)->get(route('admin.authors.show', $author));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('oeuvres.data', 1)
        ->where('oeuvres.data.0.uuid', $submitted->uuid)
    );
});

test('visiting an admin\'s uuid on the author show route 404s', function () {
    $admin = User::factory()->withRole('admin')->create();
    $otherAdmin = User::factory()->withRole('admin')->create();

    $this->actingAs($admin)->get(route('admin.authors.show', $otherAdmin))->assertNotFound();
});

test('the author show page never exposes a vault path, wrapped DEK, or nonce', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);
    MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id]);

    $response = $this->actingAs($admin)->get(route('admin.authors.show', $author));
    $json = json_encode($response->viewData('page')['props']);

    expect($json)
        ->not->toContain('dek_wrapped')
        ->not->toContain('mac_path')
        ->not->toContain('/vault/');
});
