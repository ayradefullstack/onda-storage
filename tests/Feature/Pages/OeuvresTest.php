<?php

declare(strict_types=1);

use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\RegisterTypeCollege;
use App\Models\User;
use Database\Seeders\MembershipTypeSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function oeuvresTestAuthor(): User
{
    Role::findOrCreate('author');
    $user = User::factory()->create();
    $user->assignRole('author');

    return $user;
}

test('works index renders the author\'s works', function () {
    $user = oeuvresTestAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id, 'title' => 'Aurès Symphony']);
    MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id]);

    $this->actingAs($user)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('author/oeuvres/Index')
            ->has('oeuvres', 1)
            ->where('oeuvres.0.title', 'Aurès Symphony')
            ->where('oeuvres.0.media_files_count', 1),
        );
});

test('works index only lists the authenticated author\'s own works', function () {
    $user = oeuvresTestAuthor();
    $stranger = oeuvresTestAuthor();
    Oeuvre::factory()->create(['author_id' => $stranger->id]);

    $this->actingAs($user)
        ->get(route('oeuvres.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('author/oeuvres/Index')
            ->has('oeuvres', 0),
        );
});

test('the create page renders', function () {
    $user = oeuvresTestAuthor();

    $this->actingAs($user)
        ->get(route('oeuvres.create'))
        ->assertInertia(fn (Assert $page) => $page->component('author/oeuvres/Create'));
});

test('storing a work creates an untitled draft for the authenticated author and redirects to show', function () {
    $this->seed(MembershipTypeSeeder::class);
    $user = oeuvresTestAuthor();
    $college = RegisterTypeCollege::where('code_college', 'PRODUCTION_PHONOGRAMME')->firstOrFail();

    $response = $this->actingAs($user)->post(route('oeuvres.store'), [
        'register_type_id' => $college->register_type_id,
        'register_type_college_id' => $college->id,
        'register_type_member_id' => $college->registerTypeMembers()->where('code_qlt', '09')->value('id'),
    ]);

    $oeuvre = Oeuvre::where('author_id', $user->id)->firstOrFail();
    expect($oeuvre->status)->toBe('draft')
        ->and($oeuvre->title)->toBeNull();

    $response->assertRedirect(route('oeuvres.show', $oeuvre));
});

test('storing a work requires a classification', function () {
    $user = oeuvresTestAuthor();

    $this->actingAs($user)
        ->post(route('oeuvres.store'), [])
        ->assertSessionHasErrors(['register_type_id', 'register_type_college_id', 'register_type_member_id']);

    expect(Oeuvre::count())->toBe(0);
});

test('the show page renders the work and its media files for the owner', function () {
    $user = oeuvresTestAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $user->id]);
    $mediaFile = MediaFile::factory()->create([
        'oeuvre_id' => $oeuvre->id,
        'status' => 'ready',
        'original_name' => 'movie.mp4',
    ]);

    $this->actingAs($user)
        ->get(route('oeuvres.show', $oeuvre))
        ->assertInertia(fn (Assert $page) => $page
            ->component('author/oeuvres/Show')
            ->where('oeuvre.uuid', $oeuvre->uuid)
            ->has('mediaFiles', 1)
            ->where('mediaFiles.0.uuid', $mediaFile->uuid)
            ->where('mediaFiles.0.status', 'ready'),
        );
});

test('another author cannot view someone else\'s work', function () {
    $owner = oeuvresTestAuthor();
    $stranger = oeuvresTestAuthor();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $owner->id]);

    $this->actingAs($stranger)
        ->get(route('oeuvres.show', $oeuvre))
        ->assertForbidden();
});

test('the works routes are registered without a {locale} segment', function () {
    $oeuvresRoutes = collect(Route::getRoutes())->filter(
        fn ($route) => str_starts_with($route->uri(), 'author/oeuvres'),
    );

    expect($oeuvresRoutes)->not->toBeEmpty();

    $oeuvresRoutes->each(function ($route) {
        expect($route->uri())->not->toContain('{locale}');
    });
});
