<?php

declare(strict_types=1);

use App\Models\MediaFile;
use App\Models\Oeuvre;
use App\Models\User;
use App\Notifications\MediaFileFailedNotification;
use App\Notifications\MediaFileQuarantinedNotification;
use App\Notifications\MediaFileRejected;
use App\Notifications\OeuvreRegisteredNotification;
use App\Notifications\OeuvreRejectedNotification;
use App\Notifications\OeuvreSubmittedNotification;
use App\Notifications\OeuvreUnderReviewNotification;
use App\Notifications\ReplacementFileUploaded;
use Illuminate\Support\Arr;

/** Render under a DIFFERENT worker locale: the mail must follow its captured one. */
function nlRender(object $notification, User $user, string $locale): string
{
    $previous = app()->getLocale();
    app()->setLocale($locale === 'en' ? 'fr' : 'en');

    try {
        return $notification->toMail($user)->render();
    } finally {
        app()->setLocale($previous);
    }
}

function nlText(string $html): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function nlRootDir(string $html): ?string
{
    return preg_match('/<html\b[^>]*?\sdir="([^"]*)"/i', $html, $m) ? $m[1] : null;
}

/** Typed literally, never through __(), so a lang typo cannot hide itself. */
function nlExpected(): array
{
    return [
        'failed' => [
            'fr' => ['Échec du traitement de votre dépôt', 'Le traitement de votre fichier « scan.pdf » a échoué.', 'Tous droits réservés.', 'République Algérienne Démocratique et Populaire'],
            'en' => ['Processing of your deposit failed', 'The processing of your file "scan.pdf" has failed.', 'All rights reserved.', "People's Democratic Republic of Algeria"],
            'ar' => ['فشلت معالجة إيداعكم', 'فشلت معالجة ملفكم «scan.pdf».', 'جميع الحقوق محفوظة.', 'الجمهورية الجزائرية الديمقراطية الشعبية'],
        ],
        'quarantined' => [
            'fr' => ['Fichier mis en quarantaine', "a été mis en quarantaine par l'analyse antivirus.", 'Tous droits réservés.'],
            'en' => ['File quarantined', 'was quarantined by the antivirus scan.', 'All rights reserved.'],
            'ar' => ['ملف في الحجر الصحي', 'في الحجر الصحي بواسطة فحص مضادات الفيروسات.', 'جميع الحقوق محفوظة.'],
        ],
    ];
}

dataset('nlLocales', ['ar' => ['ar', 'rtl'], 'fr' => ['fr', 'ltr'], 'en' => ['en', 'ltr']]);

it('renders the file-failed mail in the captured locale with the right dir', function (string $locale, string $dir) {
    $user = User::factory()->create();
    $file = MediaFile::factory()->create(['original_name' => 'scan.pdf']);

    $html = nlRender(new MediaFileFailedNotification($file, $locale), $user, $locale);
    $text = nlText($html);

    expect(nlRootDir($html))->toBe($dir);
    foreach (nlExpected()['failed'][$locale] as $phrase) {
        expect($text)->toContain($phrase);
    }
    // The generic "you did not request this" block does not belong to a failure notice.
    expect($text)->not->toContain('Avis de sécurité')->and($text)->not->toContain('Security notice');

    if ($locale !== 'fr') {
        expect($text)->not->toContain('Bonjour')->and($text)->not->toContain('Tous droits')
            ->and($text)->not->toContain('Échec');
    }
})->with('nlLocales');

it('renders the quarantine mail in the captured locale with the right dir', function (string $locale, string $dir) {
    $user = User::factory()->create();
    $file = MediaFile::factory()->create(['original_name' => 'scan.pdf']);

    $html = nlRender(new MediaFileQuarantinedNotification($file, $locale), $user, $locale);
    $text = nlText($html);

    expect(nlRootDir($html))->toBe($dir)
        ->and($text)->toContain('scan.pdf')->and($text)->toContain($file->uuid);
    foreach (nlExpected()['quarantined'][$locale] as $phrase) {
        expect($text)->toContain($phrase);
    }
    if ($locale !== 'fr') {
        expect($text)->not->toContain('Alerte administrateur')->and($text)->not->toContain('Tous droits');
    }
})->with('nlLocales');

it('localizes the corporate footer and keeps the official names and address', function (string $locale) {
    $user = User::factory()->create();
    $file = MediaFile::factory()->create();

    $text = nlText(nlRender(new MediaFileFailedNotification($file, $locale), $user, $locale));

    $office = [
        'fr' => "Office National des Droits d'Auteur et Droits Voisins (ONDA)",
        'en' => 'National Office of Copyright and Neighbouring Rights (ONDA)',
        'ar' => 'الديوان الوطني لحقوق المؤلف والحقوق المجاورة (ONDA)',
    ];
    expect($text)->toContain($office[$locale])
        ->and($text)->toContain('Rue du Grand Séminaire, Bologhine')
        ->and($text)->toContain('© '.date('Y'))
        // The header keeps both official institutional names in every locale.
        ->and($text)->toContain("Office National des Droits d'Auteur et des Droits Voisins")
        ->and($text)->toContain('الديوان الوطني لحقوق المؤلف والحقوق المجاورة');
})->with(['ar', 'fr', 'en']);

it('defaults the locale of a queued mail to the one active at dispatch', function () {
    app()->setLocale('ar');
    $file = MediaFile::factory()->create();

    try {
        expect((new MediaFileFailedNotification($file))->locale)->toBe('ar')
            ->and((new MediaFileQuarantinedNotification($file))->locale)->toBe('ar');
    } finally {
        app()->setLocale('en');
    }
});

it('keeps lang/{ar,fr,en}/mail.php in key parity', function () {
    $keys = function (string $l): array {
        $k = array_keys(Arr::dot(require lang_path("$l/mail.php")));
        sort($k);

        return $k;
    };

    expect($keys('ar'))->toBe($keys('fr'))->and($keys('en'))->toBe($keys('fr'));
});

it('never uses the oe ligature in the new lang files or notifications', function () {
    foreach (['ar', 'fr', 'en'] as $l) {
        expect(file_get_contents(lang_path("$l/mail.php")))->not->toContain("\u{0153}");
    }
    foreach (glob(app_path('Notifications/*.php')) as $f) {
        expect(file_get_contents($f))->not->toContain("\u{0153}");
    }
});

/** A UI-bundle string by dotted key (the bundles are nested objects). */
function nlBundleText(string $locale, string $key): string
{
    $tree = json_decode((string) file_get_contents(resource_path("js/locales/$locale.json")), true, flags: JSON_THROW_ON_ERROR);
    // Bundles mix nesting and dotted keys: try every prefix split.
    $find = function (array $node, string $rest) use (&$find): ?string {
        if (isset($node[$rest]) && is_string($node[$rest])) {
            return $node[$rest];
        }
        $parts = explode('.', $rest);
        for ($i = 1; $i < count($parts); $i++) {
            $head = implode('.', array_slice($parts, 0, $i));
            if (isset($node[$head]) && is_array($node[$head])) {
                $hit = $find($node[$head], implode('.', array_slice($parts, $i)));
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    };

    return (string) $find($tree, $key);
}

/**
 * Bell rows store a key plus parameters, never a sentence: the bell renders
 * them in the READER's locale. The sentences are asserted literally against
 * the shipped UI bundles.
 */
it('stores keys and params in the bell payload, never a rendered sentence', function () {
    $author = User::factory()->create(['name' => 'Amina Test']);
    $oeuvre = Oeuvre::factory()->create(['author_id' => $author->id, 'registered_at' => now()]);
    $file = MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id, 'original_name' => 'new.pdf']);
    $old = MediaFile::factory()->create(['oeuvre_id' => $oeuvre->id, 'original_name' => 'old.pdf', 'review_reason' => 'Illisible']);
    $reader = User::factory()->create();

    $rows = [
        'notifications.oeuvre.registered' => (new OeuvreRegisteredNotification($oeuvre))->toArray($reader),
        'notifications.oeuvre.rejected' => (new OeuvreRejectedNotification($oeuvre, 'Motif manuel'))->toArray($reader),
        'notifications.oeuvre.under_review' => (new OeuvreUnderReviewNotification($oeuvre))->toArray($reader),
        'notifications.oeuvre.submitted' => (new OeuvreSubmittedNotification($oeuvre, false))->toArray($reader),
        'notifications.oeuvre.resubmitted' => (new OeuvreSubmittedNotification($oeuvre, true))->toArray($reader),
        'notifications.file.rejected' => (new MediaFileRejected($oeuvre, $file, 'Flou', now()))->toArray($reader),
        'notifications.file.replacement' => (new ReplacementFileUploaded($oeuvre, $file, $old))->toArray($reader),
    ];

    foreach ($rows as $key => $payload) {
        expect($payload['i18n_key'])->toBe($key)->and($payload['params'])->toBeArray();
    }

    // Free text the officer wrote travels verbatim, outside the translated sentence.
    expect($rows['notifications.oeuvre.rejected']['reason'])->toBe('Motif manuel')
        ->and($rows['notifications.file.rejected']['params'])->toBe(['file' => 'new.pdf'])
        ->and($rows['notifications.file.replacement']['params']['old'])->toBe('old.pdf');

    $sentences = [
        'notifications.oeuvre.registered' => ['fr' => 'Votre dépôt a été enregistré.', 'en' => 'Your deposit has been registered.', 'ar' => 'تم تسجيل إيداعك.'],
        'notifications.oeuvre.rejected' => ['fr' => 'Votre dépôt a été rejeté.', 'en' => 'Your deposit has been rejected.', 'ar' => 'تم رفض إيداعك.'],
        'notifications.oeuvre.under_review' => ['fr' => "Votre dépôt est en cours d'examen par l'ONDA.", 'en' => 'Your deposit is under review by ONDA.', 'ar' => 'إيداعك قيد المراجعة من طرف ONDA.'],
        'notifications.oeuvre.submitted' => ['fr' => '{author} a soumis un dépôt ({college}).', 'en' => '{author} submitted a deposit ({college}).', 'ar' => 'قدّم {author} إيداعًا ({college}).'],
        'notifications.file.rejected' => ['fr' => 'Le fichier « {file} » a été rejeté.', 'en' => 'The file “{file}” was rejected.', 'ar' => 'تم رفض الملف « {file} ».'],
        'notifications.file.replacement' => ['fr' => 'Fichier de remplacement « {file} » prêt à examiner (remplace « {old} »).', 'en' => 'Replacement file “{file}” is ready for review (replaces “{old}”).', 'ar' => 'الملف البديل « {file} » جاهز للمراجعة (يحلّ محلّ « {old} »).'],
    ];
    foreach ($sentences as $key => $perLocale) {
        foreach ($perLocale as $locale => $sentence) {
            expect(nlBundleText($locale, $key))->toBe($sentence);
        }
    }
});
