<?php

use Illuminate\Support\Facades\Route;

/**
 * Admin authenticates through the same `web` guard and the same Fortify
 * `/login` as everyone else (see config/auth.php and LoginResponse) — this
 * file only adds the `role:admin` boundary on top. No controller class yet:
 * `Route::inertia()` is the same convention the author dashboard uses below
 * for a page with no server-side logic of its own; a real controller
 * arrives with the admin review console (out of scope here).
 */
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('dashboard', 'admin/Dashboard')->name('dashboard');
});
