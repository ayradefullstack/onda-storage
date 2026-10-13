<?php

declare(strict_types=1);

/**
 * The first-paint loader lives in app.blade.php as plain HTML + inline CSS,
 * so it is on screen before any script or stylesheet. AppLoader.vue removes
 * it once the Vue app has mounted (AppLoader.test.ts).
 */
test('the first page load carries the inline loader, before the app root', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)
        ->toContain('id="app-initial-loader"')
        ->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('#app-initial-loader {');

    // It is the first thing in <body>, ahead of the Inertia root.
    expect(strpos($html, 'id="app-initial-loader"'))
        ->toBeGreaterThan(strpos($html, '<body'))
        ->toBeLessThan(strpos($html, 'data-page'));
});

test('it is the static version of the AppLoader design', function () {
    $html = $this->withUnencryptedCookie('locale', 'fr')->get('/login')->getContent();

    expect($html)
        // logo tile inside the arc (original gradient) and the dashed ring
        ->toContain('class="ail-tile"')
        ->toContain('/assets/logos/logo.png')
        ->toContain('stroke-dasharray="140 320"')
        ->toContain('stroke-dasharray="6 10"')
        ->toContain('#1B669D')
        // badge and status line in the page language
        ->toContain('Portail Numérique Officiel des Droits d&#039;Auteur')
        ->toContain('Chargement de l&#039;espace ONDA...')
        // the institution's name and the footer, always bilingual
        ->toContain('الديوان الوطني لحقوق المؤلف والحقوق المجاورة')
        ->toContain("Office National des Droits d'Auteur et des Droits Voisins")
        ->toContain('الجمهورية الجزائرية الديمقراطية الشعبية')
        ->toContain('ONDA Algérie');
});

test('it needs no script and never traps the user', function () {
    $html = $this->get('/login')->getContent();
    $loader = substr($html, strpos($html, 'id="app-initial-loader"'));
    // Up to its last element, the footer line.
    $loader = substr($loader, 0, strpos($loader, 'ONDA Algérie'));

    expect(strlen($loader))->toBeGreaterThan(500);

    // Markup only: no script inside the loader, hidden when JS is off, and
    // released on its own if the app never mounts.
    expect($loader)->not->toContain('<script');
    expect($html)
        ->toContain('<noscript><style>#app-initial-loader { display: none; }</style></noscript>')
        ->toContain('app-initial-loader-release')
        ->toContain('prefers-reduced-motion: reduce');
});

test('it is dark from the first byte when the appearance cookie says dark', function () {
    $html = $this->withUnencryptedCookie('appearance', 'dark')->get('/login')->getContent();

    // The server puts `dark` on <html>; the loader's dark rule keys on it.
    expect($html)->toMatch('/<html[^>]*class="[^"]*\bdark\b/');
    expect($html)->toContain('html.dark #app-initial-loader');

    $light = $this->withUnencryptedCookie('appearance', 'light')->get('/login')->getContent();

    expect($light)->not->toMatch('/<html[^>]*class="[^"]*\bdark\b/');
});

test('its label follows the page language', function (string $locale, string $label) {
    $html = $this->withUnencryptedCookie('locale', $locale)->get('/login')->getContent();

    expect($html)->toContain('aria-label="'.$label.'"');
})->with([
    ['ar', 'جارٍ التحميل…'],
    ['fr', 'Chargement…'],
    ['en', 'Loading…'],
]);
