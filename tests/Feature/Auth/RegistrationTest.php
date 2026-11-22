<?php

use App\Models\Commune;
use App\Models\Country;
use App\Models\User;
use App\Models\Wilaya;
use App\Notifications\Auth\AccountCreatedNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->seed(RoleSeeder::class);
});

/**
 * @return array{0: Country, 1: Wilaya, 2: Commune}
 */
function regDzFixture(): array
{
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

    return [$country, $wilaya, $commune];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function regPayload(Country $country, ?Wilaya $wilaya, ?Commune $commune, array $overrides = []): array
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

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    [$country, $wilaya, $commune] = regDzFixture();

    $response = $this->post(route('register.store'), regPayload($country, $wilaya, $commune));

    $this->assertAuthenticated();
    $response->assertRedirect(route('author.dashboard', absolute: false));
});

test('a valid DZ registration creates one unverified author with a hashed password', function () {
    [$country, $wilaya, $commune] = regDzFixture();
    Event::fake([Registered::class]);

    $this->post(route('register.store'), regPayload($country, $wilaya, $commune))
        ->assertRedirect(route('author.dashboard', absolute: false));

    expect(User::count())->toBe(1);
    $user = User::firstOrFail();

    expect($user->getRoleNames()->all())->toBe(['author'])
        ->and($user->permissions)->toHaveCount(0)
        ->and(Hash::check('password', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('password')
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->country_id)->toBe($country->id)
        ->and($user->wilaya_id)->toBe($wilaya->id)
        ->and($user->commune_id)->toBe($commune->id);

    $this->assertAuthenticatedAs($user);
    Event::assertDispatchedTimes(Registered::class, 1);
});

test('registration sends exactly one verification notification', function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
    [$country, $wilaya, $commune] = regDzFixture();
    Notification::fake();

    $this->post(route('register.store'), regPayload($country, $wilaya, $commune));

    $user = User::firstOrFail();
    Notification::assertSentToTimes($user, AccountCreatedNotification::class, 1);
    Notification::assertCount(1);
});

test('a non-DZ registration needs no wilaya or commune and keeps the city', function () {
    $france = Country::create(['name' => 'France', 'alpha2' => 'FR']);

    $this->post(route('register.store'), regPayload($france, null, null, [
        'city' => 'Lyon',
        'email' => 'fr@example.com',
    ]))->assertRedirect(route('author.dashboard', absolute: false));

    $user = User::where('email', 'fr@example.com')->firstOrFail();
    expect($user->country_id)->toBe($france->id)
        ->and($user->wilaya_id)->toBeNull()
        ->and($user->commune_id)->toBeNull()
        ->and($user->city)->toBe('Lyon')
        ->and($user->getRoleNames()->all())->toBe(['author']);
});

test('privileged and server-owned fields in the payload are ignored', function () {
    [$country, $wilaya, $commune] = regDzFixture();
    Role::findOrCreate('admin');

    $this->post(route('register.store'), regPayload($country, $wilaya, $commune, [
        'role' => 'admin',
        'roles' => ['admin'],
        'permissions' => ['anything'],
        'email_verified_at' => now()->toDateTimeString(),
        'two_factor_secret' => 'SECRET',
        'two_factor_confirmed_at' => now()->toDateTimeString(),
        'uuid' => '00000000-0000-7000-8000-000000000000',
        'id' => 999999,
        'remember_token' => 'forged',
    ]));

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->getRoleNames()->all())->toBe(['author'])
        ->and($user->permissions)->toHaveCount(0)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->uuid)->not->toBe('00000000-0000-7000-8000-000000000000')
        ->and($user->uuid)->not->toBeEmpty()
        ->and($user->id)->not->toBe(999999)
        ->and($user->remember_token)->not->toBe('forged');
});

test('an uppercase email equal to an existing lowercase email is rejected', function () {
    [$country, $wilaya, $commune] = regDzFixture();
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register.store'), regPayload($country, $wilaya, $commune, ['email' => 'TAKEN@EXAMPLE.COM']))
        ->assertSessionHasErrors('email');

    expect(User::count())->toBe(1);
    $this->assertGuest();
});

test('a short or unconfirmed password is rejected', function () {
    [$country, $wilaya, $commune] = regDzFixture();

    $this->post(route('register.store'), regPayload($country, $wilaya, $commune, [
        'password' => 'abc', 'password_confirmation' => 'abc',
    ]))->assertSessionHasErrors('password');

    $this->post(route('register.store'), regPayload($country, $wilaya, $commune, [
        'password_confirmation' => 'different-password',
    ]))->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

test('arabic name fields reject non-arabic text', function () {
    [$country, $wilaya, $commune] = regDzFixture();

    $this->post(route('register.store'), regPayload($country, $wilaya, $commune, ['first_name_ar' => 'Mohamed']))
        ->assertSessionHasErrors('first_name_ar');

    $this->post(route('register.store'), regPayload($country, $wilaya, $commune, ['last_name_ar' => 'Benali']))
        ->assertSessionHasErrors('last_name_ar');

    expect(User::count())->toBe(0);
});
