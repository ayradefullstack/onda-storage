import { createI18n } from 'vue-i18n';
import type { Language } from '@/types/language';

/**
 * Which locales exist is decided by the backend (`languages` table) and
 * arrives as the shared `languages` Inertia prop — there is no list here.
 * Only the UI-string bundles are discovered at build time: dropping a
 * `locales/{code}.json` file in is all a new language needs frontend-side.
 * A language without a bundle renders in the default language's strings.
 */
type Messages = Record<string, string>;

const bundles = Object.fromEntries(
    Object.entries(
        // Relative on purpose: the app's Vite config has no `@` alias, so an
        // aliased glob silently matches nothing and every string goes blank.
        import.meta.glob<{ default: Messages }>('./locales/*.json', {
            eager: true,
        }),
    ).map(([path, module]) => [
        path.replace(/^.*\/([^/]+)\.json$/, '$1'),
        module.default,
    ]),
);

export function findLanguage(
    languages: Language[],
    code: unknown,
): Language | undefined {
    return languages.find((language) => language.code === code);
}

export function defaultLanguageCode(languages: Language[]): string {
    return (languages.find((language) => language.is_default) ?? languages[0])
        ?.code;
}

/**
 * `numberingSystem: 'latn'` is forced explicitly rather than relying on the
 * `ar-DZ` locale tag's CLDR default (Maghreb Arabic defaults to Western
 * digits, Mashriq Arabic does not) — ICU data completeness varies across
 * browsers/Node builds, so the explicit override is the only version that
 * can't silently regress. Applied to every right-to-left language, since
 * the portal shows Western digits throughout.
 */
function numberFormatsFor(languages: Language[]) {
    return Object.fromEntries(
        languages.map((language) => {
            const numbering =
                language.direction === 'rtl' ? { numberingSystem: 'latn' } : {};

            return [
                language.code,
                {
                    decimal: numbering,
                    integer: { ...numbering, maximumFractionDigits: 0 },
                },
            ];
        }),
    );
}

export function createAppI18n(locale: string, languages: Language[]) {
    return createI18n({
        legacy: false,
        locale,
        fallbackLocale: defaultLanguageCode(languages),
        messages: bundles,
        numberFormats: numberFormatsFor(languages),
    });
}

/**
 * Sets `dir`/`lang` on <html>. Needed after any locale change that isn't a
 * full page load — Blade only sets these attributes once, on first render.
 * The direction comes from the language's own `direction` column.
 */
export function applyHtmlDirLang(language: Language): void {
    const html = document.documentElement;
    html.dir = language.direction;
    html.lang = language.code;
}

/**
 * Applies a locale change reactively, client-side, with no page reload.
 *
 * Inertia visits (e.g. after login) swap the page component via XHR — they
 * never re-run app.blade.php. So `dir`/`lang` on <html>, set by Blade on the
 * very first load, and vue-i18n's active locale, fixed once at app boot,
 * both need to be re-applied by hand after every such navigation or the UI
 * silently keeps rendering the old language until a hard refresh.
 */
export function applyLocale(
    i18n: ReturnType<typeof createAppI18n>,
    language: Language,
): void {
    i18n.global.locale.value = language.code;
    applyHtmlDirLang(language);
}

/**
 * Writes the `locale` cookie directly from the client — mirrors how
 * `appearance` (see useAppearance.ts) is persisted. SetLocale only ever
 * reads this cookie server-side, so no request is needed just to save the
 * preference; it's picked up on the next full page load.
 */
export function persistLocaleCookie(locale: string): void {
    const maxAge = 60 * 60 * 24 * 365;
    document.cookie = `locale=${locale};path=/;max-age=${maxAge};SameSite=Lax`;
}

/**
 * Rewrites a leading locale segment in `url` (path + optional query/hash) to
 * `locale`, mirroring LocaleController::resolveRedirectPath server-side.
 * Only the `home` route (`/{locale}`) has such a segment — every other URL
 * is returned unchanged, since its locale lives in the cookie, not the path.
 */
export function withLocaleSegment(
    url: string,
    locale: string,
    languages: Language[],
): string {
    const parsed = new URL(url, 'http://localhost');
    const segments = parsed.pathname.split('/');

    if (findLanguage(languages, segments[1])) {
        segments[1] = locale;
        parsed.pathname = segments.join('/');
    }

    return parsed.pathname + parsed.search + parsed.hash;
}
