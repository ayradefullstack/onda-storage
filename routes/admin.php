<?php

use App\Http\Controllers\Admin\AuthorController;
use App\Http\Controllers\Admin\MediaVariantController;
use App\Http\Controllers\Admin\WorkController;
use Illuminate\Support\Facades\Route;

/**
 * Admin authenticates through the same `web` guard and the same Fortify
 * `/login` as everyone else (see config/auth.php and LoginResponse) — this
 * file only adds the `role:admin` boundary on top.
 */
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('dashboard', 'admin/Dashboard')->name('dashboard');

    Route::prefix('authors')->name('authors.')->group(function () {
        Route::get('/', [AuthorController::class, 'index'])->name('index');
        Route::get('/{user:uuid}', [AuthorController::class, 'show'])->name('show');
    });

    Route::prefix('works')->name('works.')->group(function () {
        Route::get('/', [WorkController::class, 'index'])->name('index');
        Route::get('/{work:uuid}', [WorkController::class, 'show'])->name('show');
    });

    // The review console's cheap preview path — see MediaVariantController's
    // docblock. Never exposes the vault-scale original; that's the existing
    // media.link/media.stream pair (routes/web.php), reused as-is with the
    // role gate widened to admit admin.
    Route::get('/media/{mediaFile:uuid}/variant/{kind}', [MediaVariantController::class, 'show'])
        ->where('kind', 'poster|preview|waveform')
        ->name('media.variant');
});
