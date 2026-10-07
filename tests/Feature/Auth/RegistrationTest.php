<?php

use App\Models\Commune;
use App\Models\Country;
use App\Models\Wilaya;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    // country_id must be Algeria's id for wilaya_id/commune_id to become
    // required — see CreateNewUser::create()'s required_if rules.
    $country = Country::create(['name' => 'Algeria', 'alpha2' => 'DZ']);
    $wilaya = Wilaya::create([
        'country_id' => $country->id,
        'code' => '16',
        'name_ar' => 'الجزائر',
        'name_fr' => 'Alger',
    ]);
    $commune = Commune::create([
        'wilaya_id' => $wilaya->id,
        'name_ar' => 'الجزائر الوسطى',
        'name_fr' => 'Alger Centre',
    ]);

    $response = $this->post(route('register.store'), [
        'first_name' => 'Mohamed',
        'last_name' => 'Benali',
        'first_name_ar' => 'محمد',
        'last_name_ar' => 'بن علي',
        'country_id' => $country->id,
        'wilaya_id' => $wilaya->id,
        'commune_id' => $commune->id,
        'phone' => '0512345678',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    // CreateNewUser now assigns the `author` role on self-registration
    // (see its own doc comment for why) — without that, `role:author` on
    // author.dashboard would 403 the very user this redirect just sent
    // there.
    $response->assertRedirect(route('author.dashboard', absolute: false));
});
