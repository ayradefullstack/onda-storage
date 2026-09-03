<?php

declare(strict_types=1);

use App\Models\MediaFile;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function worksTestAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

test('works index renders the author\'s works', function () {
    $user = worksTestAuthor();
    $work = Work::factory()->create(['author_id' => $user->id, 'title' => 'Aurès Symphony']);
    MediaFile::factory()->create(['work_id' => $work->id]);

    $this->actingAs($user)
        ->get(route('works.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('works/Index')
            ->has('works', 1)
            ->where('works.0.title', 'Aurès Symphony')
            ->where('works.0.media_files_count', 1),
        );
});

test('works index only lists the authenticated author\'s own works', function () {
    $user = worksTestAuthor();
    $stranger = worksTestAuthor();
    Work::factory()->create(['author_id' => $stranger->id]);

    $this->actingAs($user)
        ->get(route('works.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('works/Index')
            ->has('works', 0),
        );
});

test('the create page renders', function () {
    $user = worksTestAuthor();

    $this->actingAs($user)
        ->get(route('works.create'))
        ->assertInertia(fn (Assert $page) => $page->component('works/Create'));
});

test('storing a work creates it for the authenticated author and redirects to show', function () {
    $user = worksTestAuthor();

    $response = $this->actingAs($user)->post(route('works.store'), [
        'title' => 'Sands of Memory',
        'description' => 'A novel.',
    ]);

    $work = Work::where('title', 'Sands of Memory')->firstOrFail();
    expect($work->author_id)->toBe($user->id)
        ->and($work->status)->toBe('draft');

    $response->assertRedirect(route('works.show', $work));
});

test('storing a work requires a title', function () {
    $user = worksTestAuthor();

    $this->actingAs($user)
        ->post(route('works.store'), ['title' => ''])
        ->assertSessionHasErrors('title');
});

test('the show page renders the work and its media files for the owner', function () {
    $user = worksTestAuthor();
    $work = Work::factory()->create(['author_id' => $user->id]);
    $mediaFile = MediaFile::factory()->create([
        'work_id' => $work->id,
        'status' => 'ready',
        'original_name' => 'movie.mp4',
    ]);

    $this->actingAs($user)
        ->get(route('works.show', $work))
        ->assertInertia(fn (Assert $page) => $page
            ->component('works/Show')
            ->where('work.uuid', $work->uuid)
            ->has('mediaFiles', 1)
            ->where('mediaFiles.0.uuid', $mediaFile->uuid)
            ->where('mediaFiles.0.status', 'ready'),
        );
});

test('another author cannot view someone else\'s work', function () {
    $owner = worksTestAuthor();
    $stranger = worksTestAuthor();
    $work = Work::factory()->create(['author_id' => $owner->id]);

    $this->actingAs($stranger)
        ->get(route('works.show', $work))
        ->assertForbidden();
});

test('the works routes are registered without a {locale} segment', function () {
    $worksRoutes = collect(Route::getRoutes())->filter(
        fn ($route) => str_starts_with($route->uri(), 'works'),
    );

    expect($worksRoutes)->not->toBeEmpty();

    $worksRoutes->each(function ($route) {
        expect($route->uri())->not->toContain('{locale}');
    });
});
