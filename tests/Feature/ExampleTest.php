<?php

use App\Http\Middleware\SetLocale;

test('returns a successful response', function () {
    $response = $this->get(route('home', ['locale' => SetLocale::FALLBACK_LOCALE]));

    $response->assertOk();
});
