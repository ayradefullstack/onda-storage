<?php

use App\Models\Commune;
use App\Models\Country;
use App\Models\User;
use App\Models\Wilaya;
use App\Services\Auth\RegistrationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->seed(RoleSeeder::class);
});

/**
 * Self-contained fixtures: this file must run alone, not rely on another test file's helpers.
 *
 * @return array{0: Country, 1: Wilaya, 2: Commune}
 */
function l10nDzFixture(): array
{
    $country = Country::create(['name' => 'Algeria', 'alpha2' => 'DZ']);
    $wilaya = Wilaya::create(['country_id' => $country->id, 'code' => '16', 'name_ar' => 'الجزائر', 'name_fr' => 'Alger']);
    $commune = Commune::create(['wilaya_id' => $wilaya->id, 'name_ar' => 'الجزائر الوسطى', 'name_fr' => 'Alger Centre']);

    return [$country, $wilaya, $commune];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function l10nPayload(Country $country, ?Wilaya $wilaya, ?Commune $commune, array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Mohamed',
        'last_name' => 'Benali',
        'first_name_ar' => 'محمد',
        'last_name_ar' => 'بن علي',
        'country_id' => $country->id,
        'wilaya_id' => $wilaya?->id,
        'commune_id' => $commune?->id,
        'phone' => '0512345678',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ], $overrides);
}

/**
 * Registration is not locale-prefixed: the locale comes from the `locale` cookie.
 *
 * @param  array<string, mixed>  $payload
 */
function locPost(string $locale, array $payload): TestResponse
{
    return test()->withUnencryptedCookie('locale', $locale)->post(route('register.store'), $payload);
}

/**
 * Message the locale's lang files hold for a key: must exist (not the raw key).
 */
function locMessage(string $key, string $locale, array $replace = []): string
{
    $message = __($key, $replace, $locale);
    expect($message)->not->toBe($key);

    return $message;
}

test('DZ without wilaya and commune gets the localized required messages', function (string $locale) {
    [$country] = l10nDzFixture();

    $errors = locPost($locale, l10nPayload($country, null, null))
        ->assertSessionHasErrors(['wilaya_id', 'commune_id'])
        ->baseResponse->getSession()->get('errors')->getBag('default');

    foreach (['wilaya_id', 'commune_id'] as $field) {
        $expected = locMessage("validation.custom.{$field}.required", $locale);

        expect($errors->first($field))->toBe($expected)
            ->and($errors->first($field))->not->toBe(__('validation.required', ['attribute' => $field], $locale));
    }
})->with(['ar', 'fr', 'en']);

test('a non-integer wilaya gets the selection wording, not the generic integer text', function (string $locale) {
    [$country, , $commune] = l10nDzFixture();

    $errors = locPost($locale, l10nPayload($country, null, $commune, ['wilaya_id' => 'abc']))
        ->assertSessionHasErrors('wilaya_id')
        ->baseResponse->getSession()->get('errors')->getBag('default');

    $expected = locMessage('validation.custom.wilaya_id.integer', $locale);

    expect($errors->first('wilaya_id'))->toBe($expected);
})->with(['ar', 'fr', 'en']);

test('a duplicate email shows the same message on the validation path and the race path', function (string $locale) {
    [$country, $wilaya, $commune] = l10nDzFixture();
    User::factory()->create(['email' => 'dup@example.com']);

    $normal = locPost($locale, l10nPayload($country, $wilaya, $commune, ['email' => 'dup@example.com']))
        ->assertSessionHasErrors('email')
        ->baseResponse->getSession()->get('errors')->getBag('default')->first('email');

    // Race path: the service is reached without request validation.
    app()->setLocale($locale);
    try {
        app(RegistrationService::class)->register([
            'first_name' => 'A', 'last_name' => 'B', 'first_name_ar' => 'محمد', 'last_name_ar' => 'علي',
            'country_id' => $country->id, 'phone' => '0512345678',
            'email' => 'dup@example.com', 'password' => 'password',
        ]);
        $race = null;
    } catch (ValidationException $e) {
        $race = $e->errors()['email'][0] ?? null;
    }

    expect($normal)->toBe(locMessage('validation.custom.email.unique', $locale))
        ->and($race)->toBe($normal);
})->with(['ar', 'fr', 'en']);

test('a JSON client gets 422 with an error per invalid field', function () {
    [$country] = l10nDzFixture();

    $this->postJson(route('register.store'), l10nPayload($country, null, null, [
        'email' => 'not-an-email', 'password_confirmation' => 'nope', 'phone' => '',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['wilaya_id', 'commune_id', 'email', 'password', 'phone']);

    expect(User::count())->toBe(0);
});

test('an Inertia client is redirected back with the same error keys in the session', function () {
    [$country] = l10nDzFixture();

    $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->from('/register')
        ->post(route('register.store'), l10nPayload($country, null, null, [
            'password_confirmation' => 'nope', 'phone' => '',
        ]))
        ->assertRedirect('/register')
        ->assertSessionHasErrors(['wilaya_id', 'commune_id', 'password', 'phone']);

    expect(User::count())->toBe(0);
});

test('city is optional, capped at 120 characters, and phone is capped at 30', function () {
    $fr = Country::create(['name' => 'France', 'alpha2' => 'FR']);

    $this->post(route('register.store'), l10nPayload($fr, null, null, [
        'city' => str_repeat('x', 121), 'phone' => str_repeat('1', 31),
    ]))->assertSessionHasErrors(['city', 'phone']);

    expect(User::count())->toBe(0);
});
