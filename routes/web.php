<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Media\StreamController;
use App\Http\Controllers\Media\StreamLinkController;
use App\Http\Controllers\VaultDoctorController;
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

// Reached by an authenticated user holding neither the `author` nor the
// `admin` role — see LoginResponse::redirectPath()'s doc comment for why
// this exists and when it's expected to be hit. Not locale-prefixed, like
// every other authenticated-area route (see CLAUDE.md).
Route::middleware(['auth'])->group(function () {
    Route::inertia('account-pending', 'auth/AccountPending')->name('account.pending');
});

// P6 (scoped): the preview/stream read path only — see CLAUDE.md's phase
// log for what's deliberately deferred (full downloads, admin watermarked
// previews, production delivery). Neither role-owned — an admin and an
// author both read files here, so this lives in neither routes/admin.php
// nor routes/author.php. `role:author` today is a known, temporary gap:
// the admin review console (not yet built) will need its own access here,
// which is deliberately out of scope for this refactor — widening it now
// would be a behaviour change, not a file move. `media.link` issues a
// fresh 15-minute signed URL on demand; `media.stream` is what that URL
// points at, and carries its own `signed` check on top of the normal
// auth/role gate (see StreamController's doc comment for why all four
// layers are needed).
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
require __DIR__.'/admin.php';
require __DIR__.'/author.php';
