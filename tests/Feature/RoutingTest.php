<?php

declare(strict_types=1);

use App\Http\Middleware\SetLocale;

/**
 * Guards against non-locale-prefixed routes (e.g. `/uploads/...` in P3)
 * regressing into the `{locale}` prefix group in routes/web.php, or being
 * dropped from route registration entirely — both would 404 every
 * non-crawlable route while leaving `/{locale}/...` routes unaffected.
 */
test('a non-locale-prefixed route resolves without a {locale} route parameter', function () {
    expect(fn () => route('locale.switch', ['locale' => 'fr']))->not->toThrow(Exception::class);

    $response = $this->get('/locale/fr');

    $response->assertRedirect();
});

test('an unsupported first path segment does not get mistaken for a locale', function () {
    app()->instance('env', 'local');

    $response = $this->get('/_vault-doctor');

    // Reaches the controller (local-only guard applies) rather than 404ing
    // as if `_vault-doctor` were being matched against `{locale}`.
    expect($response->status())->not->toBe(404);
    expect(SetLocale::SUPPORTED_LOCALES)->not->toContain('_vault-doctor');
});
