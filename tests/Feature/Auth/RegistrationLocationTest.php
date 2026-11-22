<?php

use App\Models\Commune;
use App\Models\Country;
use App\Models\User;
use App\Models\Wilaya;
use Database\Seeders\RoleSeeder;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->seed(RoleSeeder::class);

    $this->dz = Country::create(['name' => 'Algeria', 'alpha2' => 'DZ']);
    $this->wilaya = Wilaya::create(['country_id' => $this->dz->id, 'code' => '16', 'name_ar' => 'الجزائر', 'name_fr' => 'Alger']);
    $this->commune = Commune::create(['wilaya_id' => $this->wilaya->id, 'name_ar' => 'الجزائر الوسطى', 'name_fr' => 'Alger Centre']);

    $this->fr = Country::create(['name' => 'France', 'alpha2' => 'FR']);
    $this->frWilaya = Wilaya::create(['country_id' => $this->fr->id, 'code' => '99', 'name_ar' => 'x', 'name_fr' => 'X']);

    $this->otherWilaya = Wilaya::create(['country_id' => $this->dz->id, 'code' => '31', 'name_ar' => 'وهران', 'name_fr' => 'Oran']);
    $this->otherCommune = Commune::create(['wilaya_id' => $this->otherWilaya->id, 'name_ar' => 'وهران', 'name_fr' => 'Oran']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function locPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Mohamed',
        'last_name' => 'Benali',
        'first_name_ar' => 'محمد',
        'last_name_ar' => 'بن علي',
        'phone' => '0512345678',
        'email' => 'loc@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $payload
 */
function locRejects(string $key, array $payload): void
{
    test()->post(route('register.store'), $payload)->assertSessionHasErrors($key);
    expect(User::count())->toBe(0);
    test()->assertGuest();
}

test('a wilaya of another country is rejected', function () {
    locRejects('wilaya_id', locPayload([
        'country_id' => $this->dz->id, 'wilaya_id' => $this->frWilaya->id, 'commune_id' => $this->commune->id,
    ]));
});

test('a commune of another wilaya is rejected', function () {
    locRejects('commune_id', locPayload([
        'country_id' => $this->dz->id, 'wilaya_id' => $this->wilaya->id, 'commune_id' => $this->otherCommune->id,
    ]));
});

test('a commune without a wilaya is rejected', function () {
    locRejects('wilaya_id', locPayload([
        'country_id' => $this->dz->id, 'commune_id' => $this->commune->id,
    ]));
});

// A wilaya that belongs to the non-DZ country itself is not covered: the
// rules only require "belongs to the chosen country".
test('a non-DZ country with an Algerian wilaya is rejected', function () {
    locRejects('wilaya_id', locPayload([
        'country_id' => $this->fr->id, 'wilaya_id' => $this->wilaya->id,
    ]));
});

test('DZ without a wilaya is rejected', function () {
    locRejects('wilaya_id', locPayload(['country_id' => $this->dz->id, 'commune_id' => $this->commune->id]));
});

test('DZ without a commune is rejected', function () {
    locRejects('commune_id', locPayload(['country_id' => $this->dz->id, 'wilaya_id' => $this->wilaya->id]));
});

test('a missing country is rejected', function () {
    locRejects('country_id', locPayload());
});

test('an unknown country is rejected', function () {
    locRejects('country_id', locPayload(['country_id' => 987654]));
});

test('inactive or hidden locations are rejected', function (string $model, string $flag) {
    $valid = ['country_id' => $this->dz->id, 'wilaya_id' => $this->wilaya->id, 'commune_id' => $this->commune->id];

    match ($model) {
        'country' => $this->dz->update([$flag => false]),
        'wilaya' => $this->wilaya->update([$flag => false]),
        'commune' => $this->commune->update([$flag => false]),
    };

    $this->post(route('register.store'), locPayload($valid))
        ->assertSessionHasErrors(["{$model}_id"]);

    expect(User::count())->toBe(0);
    $this->assertGuest();
})->with([
    'inactive country' => ['country', 'is_active'],
    'hidden country' => ['country', 'is_visible'],
    'inactive wilaya' => ['wilaya', 'is_active'],
    'hidden wilaya' => ['wilaya', 'is_visible'],
    'inactive commune' => ['commune', 'is_active'],
    'hidden commune' => ['commune', 'is_visible'],
]);

test('a soft-deleted location is rejected', function () {
    $this->commune->delete();

    locRejects('commune_id', locPayload([
        'country_id' => $this->dz->id, 'wilaya_id' => $this->wilaya->id, 'commune_id' => $this->commune->id,
    ]));
});

test('array-valued ids are rejected', function (string $field) {
    $payload = locPayload([
        'country_id' => $this->dz->id, 'wilaya_id' => $this->wilaya->id, 'commune_id' => $this->commune->id,
    ]);
    $payload[$field] = [$payload[$field]];

    locRejects($field, $payload);
})->with(['country_id', 'wilaya_id', 'commune_id']);
