<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Media\StreamController;
use App\Http\Controllers\Media\StreamLinkController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\VaultDoctorController;
use App\Http\Controllers\WorkController;
use App\Http\Middleware\SetLocale;
use App\Models\Wilaya;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/'.SetLocale::FALLBACK_LOCALE);

Route::get('locale/{locale}', LocaleController::class)
    ->where(['locale' => implode('|', SetLocale::SUPPORTED_LOCALES)])
    ->name('locale.switch');

Route::prefix('{locale}')
    ->where(['locale' => implode('|', SetLocale::SUPPORTED_LOCALES)])
    ->group(function () {
        Route::inertia('/', 'Home')->name('home');
    });

Route::get('/api/wilayas/{wilaya}/communes', function (Wilaya $wilaya) {
    return response()->json(
        $wilaya->communes()->active()->visible()->orderBy('name_fr')->get(['id', 'wilaya_id', 'post_code', 'name_fr', 'name_ar'])
    );
})->name('api.wilayas.communes');

Route::middleware(['auth', 'verified', 'role:author'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

// P4 works pages. Not locale-prefixed, like /dashboard — the authenticated
// app area's locale comes from the `locale` cookie (see SetLocale).
Route::middleware(['auth', 'verified', 'role:author'])->prefix('works')->name('works.')->group(function () {
    Route::get('/', [WorkController::class, 'index'])->name('index');
    Route::get('/create', [WorkController::class, 'create'])->name('create');
    Route::post('/', [WorkController::class, 'store'])->name('store');
    Route::get('/{work:uuid}', [WorkController::class, 'show'])->name('show');
});

Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('dashboard', 'admin/Dashboard')->name('dashboard');
});

// JSON endpoints inside the Inertia session — not an API, no Sanctum/tokens.
// Deliberately NOT locale-prefixed (see CLAUDE.md's ONDA Storage section):
// the browser calls these by absolute path regardless of active locale.
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

// P6 (scoped): the preview/stream read path only — see CLAUDE.md's phase
// log for what's deliberately deferred (full downloads, admin watermarked
// previews, production delivery). `media.link` issues a fresh 15-minute
// signed URL on demand; `media.stream` is what that URL points at, and
// carries its own `signed` check on top of the normal auth/role gate
// (see StreamController's doc comment for why all four layers are needed).
Route::middleware(['auth', 'verified', 'role:author'])->group(function () {
    Route::get('/media/{mediaFile:uuid}/link', StreamLinkController::class)
        ->name('media.link');

    Route::get('/media/{mediaFile:uuid}/stream', StreamController::class)
        ->middleware('signed')
        ->name('media.stream');
});

Route::get('/_vault-doctor', VaultDoctorController::class)->name('vault-doctor');

if (app()->environment('local')) {
    Route::get('/mail/preview', function () {
        return view('mail.corporate', [
            'subject' => 'Vérification de votre compte auteur — ONDA',
            'title' => 'Vérification de votre adresse email',
            'greeting' => 'Bonjour Mohamed Benali / مرحباً محمد بن علي,',
            'introLines' => [
                'Merci d\'avoir créé votre compte sur le Portail Numérique Officiel de l\'Office National des Droits d\'Auteur et Droits Voisins (ONDA).',
                'Afin de sécuriser votre espace et d\'activer l\'ensemble des démarches en ligne, veuillez confirmer votre adresse email en cliquant sur le bouton ci-dessous.',
            ],
            'showFeatures' => true,
            'featuresTitle' => 'Avantages & Garanties Numériques ONDA',
            'feature1Title' => 'Protection Juridique Immédiate',
            'feature1Desc' => 'Horodatage souverain et enregistrement légal de vos créations intellectuelles.',
            'feature2Title' => 'Certificats & Déclarations Numériques',
            'feature2Desc' => 'Accès instantané à vos attestations de dépôt et suivi de vos droits financiers.',
            'feature3Title' => 'Hébergement Souverain et Crypté',
            'feature3Desc' => 'Vos données et fichiers sont hébergés sur des serveurs sécurisés en Algérie.',
            'actionText' => 'Activer et vérifier mon compte',
            'actionUrl' => url('/email/verify/sample-token'),
            'warningTitle' => 'Avis de Sécurité / تنبيه أمني',
            'warningText' => 'Si vous n\'avez pas créé de compte sur le portail ONDA, veuillez ignorer cet email. Ne communiquez jamais vos identifiants ou liens de vérification.',
            'outroLines' => [
                'Pour toute assistance, contactez le support technique ONDA à contact@onda.dz.',
                'Cordialement,',
                'Direction des Systèmes d\'Information — ONDA Algérie',
            ],
        ]);
    })->name('mail.preview');
}

require __DIR__.'/settings.php';
