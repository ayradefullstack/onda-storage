<?php

declare(strict_types=1);

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Replaces the old AdminAuthenticationTest — there is no separate `admin`
 * guard to test in isolation anymore (see config/auth.php and
 * LoginResponse's doc comment). Admin and author both authenticate through
 * the same `web`-guard Fortify `/login`; what actually needs covering now
 * is the role-based landing and the role-based route boundary.
 */
function roleAccessAdmin(): User
{
    return User::factory()->withRole('admin')->create();
}

function roleAccessAuthor(): User
{
    return User::factory()->withRole('author')->create();
}

test('an admin logs in through the shared login form and lands on the admin dashboard', function () {
    $admin = roleAccessAdmin();

    $response = $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($admin);
    $response->assertRedirect(route('admin.dashboard', absolute: false));
});

test('an author logs in through the same form and lands on the author dashboard', function () {
    $author = roleAccessAuthor();

    $response = $this->post(route('login.store'), [
        'email' => $author->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($author);
    $response->assertRedirect(route('author.dashboard', absolute: false));
});

test('a roleless user logs in and reaches the account-pending page, not a 403', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('account.pending', absolute: false));

    // The redirect target itself must not 403 — that's the entire point.
    $this->followingRedirects()->get(route('account.pending'))->assertOk();
});

test('a user holding both roles lands on the admin dashboard', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('admin'), Role::findOrCreate('author'));

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('admin.dashboard', absolute: false));
});

test('an author hitting an admin route gets 403', function () {
    $author = roleAccessAuthor();

    $response = $this->actingAs($author)->get(route('admin.dashboard'));

    $response->assertForbidden();
});

test('an admin hitting an author route gets 403', function () {
    $admin = roleAccessAdmin();

    $response = $this->actingAs($admin)->get(route('author.dashboard'));

    $response->assertForbidden();
});

test('a guest hitting either dashboard is redirected to the shared login', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->get(route('author.dashboard'))->assertRedirect(route('login'));
});

// AuthenticationTest.php:65 already covers logout → '/' for an author
// through the single shared `logout` route; this confirms the same shared
// route (there is no separate admin logout anymore) does the same for an
// admin now that the guard split is gone.
test('logout goes to / for an admin too, through the single shared logout route', function () {
    $admin = roleAccessAdmin();

    $response = $this->actingAs($admin)->post(route('logout'));

    $response->assertRedirect('/');
    $this->assertGuest();
});
