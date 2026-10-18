<?php

use App\Http\Controllers\Admin\ConsultationAssetController;
use App\Http\Controllers\Admin\ConsultOriginalController;
use App\Http\Controllers\Author\DashboardController;
use App\Http\Controllers\Author\MediaFileController;
use App\Http\Controllers\Author\OeuvreController;
use App\Http\Controllers\Author\OeuvreFilePreviewController;
use App\Http\Controllers\Author\OeuvreFileStatusController;
use App\Http\Controllers\Author\OeuvreSubmissionController;
use App\Http\Controllers\Author\UploadController;
use App\Http\Middleware\EnsureConsultationAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:author'])->prefix('author')->name('author.')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
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
    Route::delete('/{oeuvre:uuid}', [OeuvreController::class, 'destroy'])->name('destroy');

    // Pipeline status of the oeuvre's files, polled by its page while a file
    // is between `complete` and a terminal status. Owner only (404 otherwise).
    Route::get('/{oeuvre:uuid}/files/status', OeuvreFileStatusController::class)->name('files.status');

    // Removing a file from a deposit. Under /author/oeuvres because that
    // is the only place it is ever reached from; the binding is the file's
    // own uuid, so nothing has to trust an oeuvre id from the request.
    // There is deliberately no counterpart that edits an oeuvre's
    // classification — see OeuvrePolicy::update()'s doc comment.
    // withTrashed: repeating a removal answers with the current state
    // instead of a 404 (RemoveMediaFile returns quietly for a removed file).
    Route::delete('/files/{mediaFile:uuid}', [MediaFileController::class, 'destroy'])->name('files.destroy')->withTrashed();

    // Preview of the author's OWN files, through the same consultation module
    // as the admin review. The file is resolved through the oeuvre (scoped
    // bindings) and ownership is a 404, never a 403. The asset and original
    // routes reuse the admin controllers; the signed URL carries the issuing
    // author's id (`u`), checked by EnsureConsultationAccess.
    Route::scopeBindings()->group(function () {
        Route::get('/{oeuvre:uuid}/files/{mediaFile:uuid}/preview', [OeuvreFilePreviewController::class, 'show'])
            ->name('files.preview');
        Route::get('/{oeuvre:uuid}/files/{mediaFile:uuid}/preview/assets', [OeuvreFilePreviewController::class, 'assets'])
            ->name('files.preview.assets');
        Route::get('/{oeuvre:uuid}/files/{mediaFile:uuid}/consult/{asset}', ConsultationAssetController::class)
            ->middleware(['signed', EnsureConsultationAccess::class])
            ->name('files.consult.asset');
        Route::get('/{oeuvre:uuid}/files/{mediaFile:uuid}/original', ConsultOriginalController::class)
            ->middleware(['signed', EnsureConsultationAccess::class])
            ->name('files.consult.original');
    });
});

// Named `author.oeuvres.submit` as the deposit brief specifies, which is
// why it sits in its own group rather than alongside the `oeuvres.*` page
// routes above (those predate the brief and keep their unprefixed names —
// see the comment on that group).
Route::middleware(['auth', 'verified', 'role:author'])->prefix('author/oeuvres')->name('author.oeuvres.')->group(function () {
    Route::post('/{oeuvre:uuid}/submit', [OeuvreSubmissionController::class, 'store'])->name('submit');
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
        ->middleware('throttle:upload-init')
        ->name('init');

    Route::get('/{session:uuid}', [UploadController::class, 'status'])
        ->name('status');

    // Per-session + per-user buckets (see AppServiceProvider::configureRateLimiting):
    // sized for `maxInFlight` chunks at full local speed, and never shared
    // with the init limiter.
    Route::post('/{session:uuid}/chunk/{index}', [UploadController::class, 'chunk'])
        ->whereNumber('index')
        ->middleware('throttle:upload-chunk')
        ->name('chunk');

    Route::post('/{session:uuid}/complete', [UploadController::class, 'complete'])
        ->name('complete');

    Route::delete('/{session:uuid}', [UploadController::class, 'abort'])
        ->name('abort');
});
