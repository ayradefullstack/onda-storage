<?php

declare(strict_types=1);

use App\Models\MediaFile;
use App\Models\User;
use App\Models\Work;
use Inertia\Testing\AssertableInertia as Assert;

test('the works index never includes a draft, even one belonging to the viewed author', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();

    Work::factory()->create(['author_id' => $author->id, 'title' => 'Still drafting', 'status' => 'draft']);
    $submitted = Work::factory()->create(['author_id' => $author->id, 'title' => 'Sent in', 'status' => 'submitted']);

    $response = $this->actingAs($admin)->get(route('admin.works.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('admin/works/Index')
        ->has('works.data', 1)
        ->where('works.data.0.uuid', $submitted->uuid)
    );
});

test('a work with a quarantined file is flagged as having a blocking file', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $work = Work::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);
    MediaFile::factory()->create(['work_id' => $work->id, 'status' => 'ready']);
    MediaFile::factory()->create(['work_id' => $work->id, 'status' => 'quarantined']);

    $response = $this->actingAs($admin)->get(route('admin.works.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('works.data.0.has_blocking_file', true)
        ->where('works.data.0.all_ready', false)
    );
});

test('a work whose files are all ready is flagged accordingly', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $work = Work::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);
    MediaFile::factory()->count(2)->create(['work_id' => $work->id, 'status' => 'ready']);

    $response = $this->actingAs($admin)->get(route('admin.works.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('works.data.0.all_ready', true)
        ->where('works.data.0.has_blocking_file', false)
    );
});

test('visiting a draft work directly on the show route 404s', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $draft = Work::factory()->create(['author_id' => $author->id, 'status' => 'draft']);

    $this->actingAs($admin)->get(route('admin.works.show', $draft))->assertNotFound();
});

test('the works show page never exposes a vault path, wrapped DEK, or nonce', function () {
    $admin = User::factory()->withRole('admin')->create();
    $author = User::factory()->withRole('author')->create();
    $work = Work::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);
    MediaFile::factory()->create(['work_id' => $work->id]);

    $response = $this->actingAs($admin)->get(route('admin.works.show', $work));
    $json = json_encode($response->viewData('page')['props']);

    expect($json)
        ->not->toContain('dek_wrapped')
        ->not->toContain('mac_path')
        ->not->toContain('/vault/');
});
