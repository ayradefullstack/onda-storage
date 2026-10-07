<?php

use App\Domain\Localization\LanguageService;

test('returns a successful response', function () {
    $response = $this->get(route('home', ['locale' => app(LanguageService::class)->defaultCode()]));

    $response->assertOk();
});
