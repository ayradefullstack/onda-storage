<?php

use App\Models\Country;
use App\Models\User;
use App\Services\Auth\RegistrationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->seed(RoleSeeder::class);
    $this->country = Country::create(['name' => 'France', 'alpha2' => 'FR']);
});

/**
 * @return array<string, mixed>
 */
function atomPayload(Country $country): array
{
    return [
        'first_name' => 'Mohamed',
        'last_name' => 'Benali',
        'first_name_ar' => 'محمد',
        'last_name_ar' => 'بن علي',
        'country_id' => $country->id,
        'phone' => '0512345678',
        'email' => 'atom@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ];
}

test('role missing rolls the user back, fires nothing and shows a form-level error', function () {
    Role::query()->where('name', 'author')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Event::fake([Registered::class]);
    Notification::fake();

    $this->post(route('register.store'), atomPayload($this->country))
        ->assertSessionHasErrors('registration')
        ->assertSessionDoesntHaveErrors('email');

    expect(User::withTrashed()->count())->toBe(0)
        ->and(session('errors')->first('registration'))->toBe(__('auth.registration_unavailable'));
    $this->assertGuest();
    Event::assertNotDispatched(Registered::class);
    Notification::assertNothingSent();
    expect(DB::table('notifications')->count())->toBe(0);
});

test('role missing answers JSON requests with 503 and the localized message', function () {
    Role::query()->where('name', 'author')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->postJson(route('register.store'), atomPayload($this->country))
        ->assertStatus(503)
        ->assertExactJson(['message' => __('auth.registration_unavailable')]);

    expect(User::withTrashed()->count())->toBe(0);
    $this->assertGuest();
});

test('role missing logs a critical entry without any personal data', function () {
    Role::query()->where('name', 'author')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Log::spy();

    $this->post(route('register.store'), atomPayload($this->country))
        ->assertSessionHasErrors('registration');

    Log::shouldHaveReceived('critical')
        ->once()
        ->withArgs(function (string $message, array $context = []): bool {
            return $context === ['role' => 'author', 'guard' => 'web']
                && ! str_contains($message, 'atom@example.com');
        });
});

test('an existing author role is assigned on registration', function () {
    $this->post(route('register.store'), atomPayload($this->country))->assertSessionHasNoErrors();

    expect(User::where('email', 'atom@example.com')->firstOrFail()->getRoleNames()->all())->toBe(['author']);
});

test('a unique violation that is not the email is rethrown, not mapped to email', function () {
    $this->withoutExceptionHandling();
    Event::fake([Registered::class]);

    // No user holds this email, so the collision cannot be the email's.
    User::creating(function (): void {
        throw new UniqueConstraintViolationException(
            'testing',
            'insert into users (phone) values (?)',
            ['0512345678'],
            new PDOException('Duplicate entry for key users_phone_unique'),
        );
    });

    expect(fn () => app(RegistrationService::class)->register(atomPayload($this->country)))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(User::withTrashed()->where('email', 'atom@example.com')->exists())->toBeFalse();
});

test('a failure assigning an existing role rolls the user back and fires nothing', function () {
    Role::findOrCreate('author');
    Event::fake([Registered::class]);
    Notification::fake();

    $hits = 0;
    DB::connection()->beforeExecuting(function (string $sql) use (&$hits): void {
        if (preg_match('/^insert into [`"]model_has_roles[`"]/', $sql) === 1) {
            $hits++;

            throw new RuntimeException('boom');
        }
    });

    $this->post(route('register.store'), atomPayload($this->country))->assertServerError();

    expect($hits)->toBe(1)
        ->and(User::count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
    $this->assertGuest();
    Event::assertNotDispatched(Registered::class);
    Notification::assertNothingSent();
    expect(DB::table('notifications')->count())->toBe(0);
});

test('a duplicate email race surfaces as an email validation error without partial rows', function () {
    User::factory()->create(['email' => 'atom@example.com']);
    $before = User::count();
    $rolesBefore = DB::table('model_has_roles')->count();

    // Bypasses request validation, as a concurrent request that passed the
    // unique rule before the other insert committed would.
    try {
        app(RegistrationService::class)->register(atomPayload($this->country));
        $thrown = null;
    } catch (ValidationException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ValidationException::class)
        ->and($thrown->errors())->toHaveKey('email')
        ->and(User::count())->toBe($before)
        ->and(DB::table('model_has_roles')->count())->toBe($rolesBefore)
        ->and(DB::table('notifications')->count())->toBe(0);
});
