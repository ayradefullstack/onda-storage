<?php

use App\Http\Controllers\Author\UploadController;
use App\Http\Controllers\Author\WorkController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:author'])->prefix('author')->name('author.')->group(function () {
    Route::inertia('dashboard', 'author/Dashboard')->name('dashboard');
});

// `works.*` deliberately keeps its (unprefixed-by-`author.`) name even
// though its path now lives under /author — nothing else in the app names
// a route `works.*`, so there is no collision forcing a rename, and every
// existing `route('works.*')` caller (frontend Wayfinder imports, Pest
// tests) keeps working unchanged. Only the URL and the controller's
// namespace move.
Route::middleware(['auth', 'verified', 'role:author'])->prefix('author/works')->name('works.')->group(function () {
    Route::get('/', [WorkController::class, 'index'])->name('index');
    Route::get('/create', [WorkController::class, 'create'])->name('create');
    Route::post('/', [WorkController::class, 'store'])->name('store');
    Route::get('/{work:uuid}', [WorkController::class, 'show'])->name('show');
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
