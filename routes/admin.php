<?php

use App\Http\Controllers\Admin\AuthorController;
use App\Http\Controllers\Admin\ConsultationAssetController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\MediaVariantController;
use App\Http\Controllers\Admin\OeuvreController;
use App\Http\Controllers\Admin\OeuvreFileReviewController;
use App\Http\Controllers\Admin\OeuvreReviewController;
use App\Http\Controllers\Admin\Referentiel\CollegeController;
use App\Http\Controllers\Admin\Referentiel\DocumentController;
use App\Http\Controllers\Admin\Referentiel\GestionController;
use App\Http\Controllers\Admin\Referentiel\MemberController;
use App\Http\Controllers\Admin\Referentiel\TypeController;
use App\Http\Middleware\EnsureConsultationAccess;
use Illuminate\Support\Facades\Route;

/**
 * Admin authenticates through the same `web` guard and the same Fortify
 * `/login` as everyone else (see config/auth.php and LoginResponse) — this
 * file only adds the `role:admin` boundary on top.
 */
Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('dashboard', 'admin/Dashboard')->name('dashboard');

    // Languages: no destroy route — retire with `is_active`. Keyed by code.
    Route::prefix('languages')->name('languages.')->group(function () {
        Route::get('/', [LanguageController::class, 'index'])->name('index');
        Route::post('/', [LanguageController::class, 'store'])->name('store');
        Route::patch('/{language}', [LanguageController::class, 'update'])->name('update');
        Route::post('/{language}/default', [LanguageController::class, 'makeDefault'])->name('make-default');
    });

    Route::prefix('authors')->name('authors.')->group(function () {
        Route::get('/', [AuthorController::class, 'index'])->name('index');
        Route::get('/{user:uuid}', [AuthorController::class, 'show'])->name('show');
    });

    Route::prefix('oeuvres')->name('oeuvres.')->group(function () {
        Route::get('/', [OeuvreController::class, 'index'])->name('index');
        Route::get('/{oeuvre:uuid}', [OeuvreController::class, 'show'])->name('show');

        // The three decisions. Every rule about which of them is legal
        // right now lives in OeuvreStatusMachine, not in this file and not
        // in the controller.
        Route::post('/{oeuvre:uuid}/review', [OeuvreReviewController::class, 'review'])->name('review');
        Route::post('/{oeuvre:uuid}/approve', [OeuvreReviewController::class, 'approve'])->name('approve');
        Route::post('/{oeuvre:uuid}/reject', [OeuvreReviewController::class, 'reject'])->name('reject');

        // File review: a deposited file shown through server-generated
        // DERIVATIVES only. The media file is resolved through the oeuvre
        // (scoped bindings), so a file of another oeuvre is a 404. The asset
        // route is signed, bound to the issuing admin, and has no path to
        // the original — see OeuvreFileReviewController's docblock.
        Route::scopeBindings()->group(function () {
            Route::get('/{oeuvre:uuid}/files/{mediaFile:uuid}/review', [OeuvreFileReviewController::class, 'show'])
                ->name('files.review');
            Route::get('/{oeuvre:uuid}/files/{mediaFile:uuid}/review/assets', [OeuvreFileReviewController::class, 'assets'])
                ->name('files.review.assets');
            Route::get('/{oeuvre:uuid}/files/{mediaFile:uuid}/consult/{asset}', ConsultationAssetController::class)
                ->middleware(['signed', EnsureConsultationAccess::class])
                ->name('files.consult.asset');
        });
    });

    /**
     * Reference data — the five seeded classification tables.
     *
     * A route per tab, not client-side tabs over one payload: a deep link
     * has to work, a refresh has to stay on the same tab, and each table
     * carries its own pagination, search and filters.
     *
     * There is deliberately NO destroy route on any of them. A college
     * with deposits filed under it must never disappear, and soft-deleting
     * one would leave those oeuvres pointing at a trashed parent —
     * retiring is `status` / `is_disabled`.
     */
    Route::prefix('referentiel')->name('referentiel.')->group(function () {
        Route::get('types', [TypeController::class, 'index'])->name('types');
        Route::post('types', [TypeController::class, 'store'])->name('types.store');
        Route::patch('types/{registerType:uuid}', [TypeController::class, 'update'])->name('types.update');

        Route::get('gestions', [GestionController::class, 'index'])->name('gestions');
        Route::post('gestions', [GestionController::class, 'store'])->name('gestions.store');
        Route::patch('gestions/{typeGestion:uuid}', [GestionController::class, 'update'])->name('gestions.update');

        Route::get('colleges', [CollegeController::class, 'index'])->name('colleges');
        Route::post('colleges', [CollegeController::class, 'store'])->name('colleges.store');
        Route::patch('colleges/{college:uuid}', [CollegeController::class, 'update'])->name('colleges.update');

        Route::get('membres', [MemberController::class, 'index'])->name('membres');
        Route::post('membres', [MemberController::class, 'store'])->name('membres.store');
        Route::patch('membres/{registerTypeMember:uuid}', [MemberController::class, 'update'])->name('membres.update');

        Route::get('documents', [DocumentController::class, 'index'])->name('documents');
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::patch('documents/{document:uuid}', [DocumentController::class, 'update'])->name('documents.update');
        // Asked from the edit dialog before `is_required` is turned on, so
        // the officer sees how many drafts it would block.
        Route::get('documents/{document:uuid}/impact', [DocumentController::class, 'impact'])->name('documents.impact');
    });

    // The review console's cheap preview path — see MediaVariantController's
    // docblock. Never exposes the vault-scale original; that's the existing
    // media.link/media.stream pair (routes/web.php), reused as-is with the
    // role gate widened to admit admin.
    Route::get('/media/{mediaFile:uuid}/variant/{kind}', [MediaVariantController::class, 'show'])
        ->where('kind', 'poster|preview|waveform')
        ->name('media.variant');
});
