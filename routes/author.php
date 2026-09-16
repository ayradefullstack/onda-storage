<?php

use App\Http\Controllers\Author\OeuvreController;
use App\Http\Controllers\Author\UploadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:author'])->prefix('author')->name('author.')->group(function () {
    Route::inertia('dashboard', 'author/Dashboard')->name('dashboard');
});

// `oeuvres.*` deliberately has no `author.` name prefix even though its
// path lives under /author — nothing else in the app names a route
// `oeuvres.*` (the admin side is `admin.oeuvres.*`), so there is no
// collision forcing one.
Route::middleware(['auth', 'verified', 'role:author'])->prefix('author/oeuvres')->name('oeuvres.')->group(function () {
    Route::get('/', [OeuvreController::class, 'index'])->name('index');
    Route::get('/create', [OeuvreController::class, 'create'])->name('create');
    Route::post('/', [OeuvreController::class, 'store'])->name('store');
    Route::get('/{oeuvre:uuid}', [OeuvreController::class, 'show'])->name('show');
});

// JSON endpoints inside the Inertia session — not an API, no Sanctum/tokens.
// Deliberately NOT moved under /author/uploads and NOT locale-prefixed (see
// CLAUDE.md's ONDA Storage section): the browser calls these by absolute
// path regardless of active locale, and the path is a client-server
// contract — the chunked upload client (`resources/js/lib/uploadClient.ts`)
// and roughly fifty literal `/uploads/...` call sites across the Pest
// upload test suite all assume it. Moving the path would mean rewriting
// every one of them for no functional gain; relocating the *file* they're
// declared in and the controller's namespace captures the organizational
// intent (this is author-only) without that cost.
Route::middleware(['auth', 'verified', 'role:author'])->prefix('uploads')->name('uploads.')->group(function () {
    Route::post('/', [UploadController::class, 'init'])
        ->middleware('throttle:10,1')
        ->name('init');

    Route::get('/{session:uuid}', [UploadController::class, 'status'])
        ->name('status');

    // 1200 requests/min comfortably covers a 5 GB file at 8 MiB chunks
    // (~640 chunks) plus retries and up to 3 concurrent chunks per file.
    Route::post('/{session:uuid}/chunk/{index}', [UploadController::class, 'chunk'])
        ->whereNumber('index')
        ->middleware('throttle:1200,1')
        ->name('chunk');

    Route::post('/{session:uuid}/complete', [UploadController::class, 'complete'])
        ->name('complete');

    Route::delete('/{session:uuid}', [UploadController::class, 'abort'])
        ->name('abort');
});
