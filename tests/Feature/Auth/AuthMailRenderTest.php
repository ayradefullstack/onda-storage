<?php

declare(strict_types=1);

use App\Models\Language;
use App\Models\User;
use App\Notifications\Auth\AccountCreatedNotification;
use App\Notifications\Auth\LoginAlertNotification;
use App\Notifications\Auth\VerifyEmailAddress;
use App\Services\Auth\LoginMethod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * The three auth mails share mail.corporate: its dir, its subcopy and its raw
 * URL island must follow the notification's locale, never a hard-coded one.
 */
function amrUser(): User
{
    return User::factory()->unverified()->create();
}

/** Render a mail the way the channel does: under the notification's locale. */
function amrRender(object $notification, User $user, string $locale): string
{
    $previous = app()->getLocale();
    app()->setLocale($locale);

    try {
        return $notification->toMail($user)->render();
    } finally {
        app()->setLocale($previous);
    }
}

/** The dir attribute of the root <html> start tag only (inner elements never count). */
function amrRootDir(string $html): ?string
{
    return preg_match('/<html\b[^>]*?\sdir="([^"]*)"/i', $html, $m) ? $m[1] : null;
}

/** The lang attribute of the root <html> start tag only. */
function amrRootLang(string $html): ?string
{
    return preg_match('/<html\b[^>]*?\slang="([^"]*)"/i', $html, $m) ? $m[1] : null;
}

/** Typed literally on purpose: never derived from __(), so a lang-file typo cannot hide itself. */
function amrSubcopyPhrases(): array
{
    return [
        'fr' => 'copiez et collez',
        'en' => 'copy and paste the URL below into your web browser',
        'ar' => 'فانسخ الرابط التالي والصقه في متصفح الويب',
    ];
}

/** The normalised (entity-decoded, whitespace-collapsed) subcopy fallback cell. */
function amrSubcopyCell(string $html): string
{
    $marker = strpos($html, '<!-- Subcopy Fallback Raw URL -->');
    expect($marker)->not->toBeFalse('subcopy marker not found');

    $start = strpos($html, '<td', (int) $marker);
    expect($start)->not->toBeFalse('subcopy cell start not found');

    $end = strpos($html, '</td>', (int) $start);
    expect($end)->not->toBeFalse('subcopy cell end not found');

    $cell = substr($html, (int) $start, (int) $end - (int) $start);

    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($cell, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** The root-direction assertion used by every dir test, so the proof test can make it fail. */
function amrAssertRootDir(string $html, string $dir): void
{
    expect(amrRootDir($html))->toBe($dir);
}

/** The recipient-facing subcopy assertion: the literal phrase must sit before the raw URL island. */
function amrAssertSubcopyPhrase(string $html, string $locale, string $phrase): void
{
    $cell = amrSubcopyCell($html);
    $pos = mb_strpos($cell, $phrase);
    expect($pos)->not->toBeFalse("[$locale] subcopy phrase missing");

    $islandPos = mb_strpos($cell, '<div dir="ltr"');
    expect($islandPos)->not->toBeFalse("[$locale] island missing");
    expect($pos)->toBeLessThan($islandPos);
}

/** Visible text of a rendered mail, entities decoded. */
function amrText(string $html): string
{
    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** @return array<string, array{0: Closure(User, string): object}> */
function amrMails(): array
{
    return [
        'verification' => [fn (User $u, string $l) => VerifyEmailAddress::for($u, $l)],
        'account created' => [fn (User $u, string $l) => AccountCreatedNotification::for($u, $l)],
        'login alert' => [fn (User $u, string $l) => new LoginAlertNotification(LoginMethod::Password, CarbonImmutable::now(), $l)],
    ];
}

dataset('amrMails', fn () => amrMails());

it('sets dir from the language table for every mail', function (Closure $make) {
    $user = amrUser();

    foreach (['ar' => 'rtl', 'fr' => 'ltr', 'en' => 'ltr'] as $locale => $dir) {
        $html = amrRender($make($user, $locale), $user, $locale);

        expect(amrRootDir($html))->toBe($dir)
            ->and(amrRootLang($html))->toBe($locale);
    }
})->with('amrMails');

it('never shows the hard-coded French subcopy in ar or en', function () {
    $user = amrUser();

    foreach (['ar', 'en'] as $locale) {
        $html = amrRender(VerifyEmailAddress::for($user, $locale), $user, $locale);

        expect($html)->not->toContain('Si vous éprouvez')
            ->and($html)->not->toContain('copiez et collez');
    }
});

it('keeps the signed verification URL intact in the button and the raw island', function () {
    $user = amrUser();

    foreach (['ar', 'fr', 'en'] as $locale) {
        $notification = VerifyEmailAddress::for($user, $locale);
        $html = amrRender($notification, $user, $locale);

        preg_match_all('/href="([^"]*email\/verify[^"]*)"/', $html, $hrefs);
        expect($hrefs[1])->not->toBeEmpty();

        $urls = array_values(array_unique(array_map('html_entity_decode', $hrefs[1])));
        expect($urls)->toHaveCount(1);
        $url = $urls[0];

        // The raw-URL island prints the same URL as text.
        $text = html_entity_decode(strip_tags(preg_replace('/\s+/', ' ', $html)));
        expect($text)->toContain($url);
        expect($html)->toMatch('/<div dir="ltr"[^>]*>\s*<a href="[^"]+"[^>]*>\s*[^<]+<\/a>/');

        $request = Request::create($url);
        expect(URL::hasValidSignature($request))->toBeTrue();
    }
});

it('falls back to ltr for an unknown or deactivated locale', function () {
    $user = amrUser();

    $html = amrRender(VerifyEmailAddress::for($user, 'zz'), $user, 'zz');
    expect(amrRootDir($html))->toBe('ltr')
        ->and($html)->not->toContain('dir="rtl"');

    $ar = Language::where('code', 'ar')->firstOrFail();
    $ar->is_active = false;
    $ar->save();

    $html = amrRender(VerifyEmailAddress::for($user, 'ar'), $user, 'ar');
    expect(amrRootDir($html))->toBe('ltr')
        ->and($html)->not->toContain('dir="rtl"');
});

it('fails the root dir assertion when only the root is wrong, even though the inner island is still ltr', function () {
    $user = amrUser();
    $html = amrRender(VerifyEmailAddress::for($user, 'fr'), $user, 'fr');

    amrAssertRootDir($html, 'ltr');

    $tweaked = preg_replace('/(<html\b[^>]*?\sdir=")ltr(")/i', '$1rtl$2', $html, 1);

    // Only the root differs: the inner URL island is untouched and still dir="ltr".
    expect($tweaked)->not->toBe($html)
        ->and($tweaked)->toContain('<div dir="ltr"');

    expect(fn () => amrAssertRootDir($tweaked, 'ltr'))
        ->toThrow(ExpectationFailedException::class);
});

it('prints the localized subcopy before the raw URL island, with the same signed URL as the button', function () {
    $user = amrUser();

    foreach (amrSubcopyPhrases() as $locale => $phrase) {
        $html = amrRender(VerifyEmailAddress::for($user, $locale), $user, $locale);
        amrAssertSubcopyPhrase($html, $locale, $phrase);

        expect(preg_match('/<div dir="ltr"[^>]*>\s*<a href="([^"]+)"/', $html, $island))->toBe(1);
        expect(preg_match('/<a href="([^"]+)"[^>]*style="background-color: #1d4ed8/', $html, $button))->toBe(1);

        $islandHref = html_entity_decode($island[1]);
        $buttonHref = html_entity_decode($button[1]);

        expect($islandHref)->toBe($buttonHref)
            ->and(URL::hasValidSignature(Request::create($islandHref)))->toBeTrue();
    }
});

it('prints the support sentence in the recipient language, with the address isolated only in Arabic', function () {
    $user = amrUser();

    $ar = amrText(amrRender(VerifyEmailAddress::for($user, 'ar'), $user, 'ar'));
    // Typed literally: sentence + LRI (U+2066) + address + PDI (U+2069).
    expect($ar)->toContain("للمساعدة، يرجى التواصل مع الدعم التقني على \u{2066}contact@onda.dz\u{2069}.");

    $fr = amrText(amrRender(VerifyEmailAddress::for($user, 'fr'), $user, 'fr'));
    expect($fr)->toContain('contactez le support technique ONDA à contact@onda.dz.')
        ->and($fr)->not->toContain("\u{2066}");

    $en = amrText(amrRender(VerifyEmailAddress::for($user, 'en'), $user, 'en'));
    expect($en)->toContain('contact ONDA technical support at contact@onda.dz.')
        ->and($en)->not->toContain("\u{2066}");
});

it('fails the subcopy assertion when the instruction is removed, even though the URL stays valid', function () {
    $user = amrUser();

    foreach (amrSubcopyPhrases() as $locale => $phrase) {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $msg = VerifyEmailAddress::for($user, $locale)->toMail($user);
            $data = $msg->viewData;
            $full = view('mail.corporate', $data)->render();
            unset($data['subcopy']);
            $html = view('mail.corporate', $data)->render();
        } finally {
            app()->setLocale($previous);
        }

        // Control: the intact mail passes the very same assertion; the stripped one must fail it.
        amrAssertSubcopyPhrase($full, $locale, $phrase);
        expect(fn () => amrAssertSubcopyPhrase($html, $locale, $phrase))
            ->toThrow(ExpectationFailedException::class);

        expect(preg_match('/<div dir="ltr"[^>]*>\s*<a href="([^"]+)"/', $html, $island))->toBe(1);
        $islandHref = html_entity_decode($island[1]);

        expect($islandHref)->toBe($data['actionUrl'])
            ->and(URL::hasValidSignature(Request::create($islandHref)))->toBeTrue();
    }
});
