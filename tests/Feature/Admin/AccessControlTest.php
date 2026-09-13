<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Work;

function adminAccessRoutes(User $author, Work $work): array
{
    return [
        'admin.authors.index' => route('admin.authors.index'),
        'admin.authors.show' => route('admin.authors.show', $author),
        'admin.works.index' => route('admin.works.index'),
        'admin.works.show' => route('admin.works.show', $work),
    ];
}

test('an author cannot reach any admin route', function () {
    $author = User::factory()->withRole('author')->create();
    $work = Work::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);

    foreach (adminAccessRoutes($author, $work) as $name => $url) {
        $this->actingAs($author)->get($url)->assertForbidden("expected [{$name}] to 403 an author");
    }
});

test('a guest is redirected to login for every admin route', function () {
    $author = User::factory()->withRole('author')->create();
    $work = Work::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);

    foreach (adminAccessRoutes($author, $work) as $name => $url) {
        $this->get($url)->assertRedirect(route('login', absolute: false), "expected [{$name}] to redirect a guest to login");
    }
});
