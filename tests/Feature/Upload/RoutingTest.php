<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Guards the routing fix from the diagnostic phase: the uploads group must
 * stay outside the `{locale}` prefix group, and must be reachable at its
 * bare path.
 */
test('every uploads route is registered without a {locale} segment', function () {
    $uploadRoutes = collect(Route::getRoutes())->filter(
        fn ($route) => str_starts_with($route->uri(), 'uploads')
    );

    expect($uploadRoutes)->not->toBeEmpty();

    $uploadRoutes->each(function ($route) {
        expect($route->uri())->not->toContain('{locale}');
    });
});

test('the init endpoint resolves to the uploads route, not a 404, for a guest', function () {
    // Unauthenticated: the `auth` middleware redirects rather than 404ing —
    // a 404 here would mean the route itself failed to register at
    // /uploads (e.g. nested back inside the {locale} prefix group).
    $response = $this->postJson('/uploads', []);

    $response->assertStatus(401);
});
