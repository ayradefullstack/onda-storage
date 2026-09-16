<?php

declare(strict_types=1);

use App\Models\Oeuvre;
use App\Models\User;

function adminAccessRoutes(User $author, Oeuvre $oeuvre): array
{
    return [
        'admin.authors.index' => route('admin.authors.index'),
        'admin.authors.show' => route('admin.authors.show', $author),
        'admin.oeuvres.index' => route('admin.oeuvres.index'),
        'admin.oeuvres.show' => route('admin.oeuvres.show', $oeuvre),
    ];
}

test('an author cannot reach any admin route', function () {
    $author = User::factory()->withRole('author')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);

    foreach (adminAccessRoutes($author, $oeuvre) as $name => $url) {
        $this->actingAs($author)->get($url)->assertForbidden("expected [{$name}] to 403 an author");
    }
});

test('a guest is redirected to login for every admin route', function () {
    $author = User::factory()->withRole('author')->create();
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'status' => 'submitted']);

    foreach (adminAccessRoutes($author, $oeuvre) as $name => $url) {
        $this->get($url)->assertRedirect(route('login', absolute: false), "expected [{$name}] to redirect a guest to login");
    }
});
