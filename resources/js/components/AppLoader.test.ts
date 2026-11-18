// @vitest-environment happy-dom
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import {
    initializeGlobalLoader,
    SHOW_DELAY_MS,
    SLOW_AFTER_MS,
    VERY_SLOW_AFTER_MS,
} from '@/composables/useGlobalLoader';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';
import AppLoader from './AppLoader.vue';

type Handler = (event: { detail: Record<string, unknown> }) => void;

const { handlers, router } = vi.hoisted(() => {
    const handlers: Record<string, Handler> = {};

    return {
        handlers,
        router: {
            on: vi.fn((name: string, handler: Handler) => {
                handlers[name] = handler;
            }),
            visit: vi.fn(),
            cancelAll: vi.fn(),
        },
    };
});

vi.mock('@inertiajs/vue3', () => ({ router }));

const URL_ = 'http://onda-storage.test/admin/oeuvres';
const page = (over: Record<string, unknown> = {}) => ({
    id: 'v1',
    url: new URL(URL_),
    method: 'get',
    only: [],
    except: [],
    reset: [],
    prefetch: false,
    showProgress: true,
    preserveState: false,
    preserveScroll: false,
    ...over,
});

const start = (over = {}): void =>
    handlers.start({ detail: { visit: page(over) } });
const finish = (over = {}): void =>
    handlers.finish({ detail: { visit: page(over) } });

let wrapper: VueWrapper | null = null;

function render(locale: 'en' | 'fr' | 'ar' = 'en'): void {
    wrapper = mount(AppLoader, {
        global: {
            plugins: [
                createI18n({
                    legacy: false,
                    locale,
                    messages: { en, fr, ar },
                    missingWarn: false,
                    fallbackWarn: false,
                }),
            ],
            stubs: { transition: false },
        },
        attachTo: document.body,
    });
}

const overlay = (): HTMLElement | null =>
    document.querySelector<HTMLElement>('[data-testid="app-loader"]');

async function wait(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
    await nextTick();
}

function reducedMotion(reduce: boolean): void {
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query: string) => ({
            matches: reduce && query.includes('prefers-reduced-motion'),
        })),
    );
}

beforeEach(() => {
    vi.useFakeTimers();
    reducedMotion(false);
    initializeGlobalLoader();
});

afterEach(async () => {
    // Leave the shared machine idle for the next test.
    handlers.httpException({ detail: {} });
    await nextTick();
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    document.documentElement.className = '';
    document.documentElement.removeAttribute('dir');
    router.visit.mockClear();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('AppLoader', () => {
    it('shows nothing for a visit shorter than 300 ms', async () => {
        render();
        start();
        await wait(SHOW_DELAY_MS - 20);
        expect(overlay()).toBeNull();

        finish();
        await wait(2_000);
        expect(overlay()).toBeNull();
    });

    it('appears after 300 ms as a polite status region and locks the page', async () => {
        render();
        document.body.insertAdjacentHTML('beforeend', '<main></main>');
        start();
        await wait(SHOW_DELAY_MS + 10);

        const el = overlay()!;

        expect(el.getAttribute('role')).toBe('status');
        expect(el.getAttribute('aria-live')).toBe('polite');
        expect(el.getAttribute('aria-label')).toBe('Loading…');
        expect(el.textContent).toContain('Loading ONDA Digital Portal...');
        expect(document.querySelector('main')!.getAttribute('aria-busy')).toBe(
            'true',
        );
        expect(document.documentElement.style.overflow).toBe('hidden');
        // It does not take focus while it is only informing.
        expect(el.contains(document.activeElement)).toBe(false);

        finish();
        await wait(1_000);

        expect(overlay()).toBeNull();
        expect(document.querySelector('main')!.hasAttribute('aria-busy')).toBe(
            false,
        );
        expect(document.documentElement.style.overflow).toBe('');
    });

    it('says "one more moment" after 3 s, then offers Retry after 15 s', async () => {
        render('fr');
        start();
        await wait(SLOW_AFTER_MS + 300);

        expect(overlay()!.dataset.phase).toBe('slow');
        expect(overlay()!.textContent).toContain('Encore un instant…');
        expect(
            document.querySelector('[data-testid="app-loader-retry"]'),
        ).toBeNull();

        await wait(VERY_SLOW_AFTER_MS - SLOW_AFTER_MS);

        const retry = document.querySelector<HTMLElement>(
            '[data-testid="app-loader-retry"]',
        )!;

        expect(overlay()!.textContent).toContain('La connexion semble lente');
        expect(retry.textContent).toContain('Réessayer');
        // Now there is something to act on: the button has the focus.
        expect(document.activeElement).toBe(retry);

        retry.click();
        await wait(50); // the leave transition's frame

        expect(router.cancelAll).toHaveBeenCalled();
        expect(router.visit).toHaveBeenCalledTimes(1);
        expect(router.visit.mock.calls[0][0]).toBe(URL_);
        expect(overlay()).toBeNull();
    });

    it('never appears for partial reloads, prefetch, silent, filter visits or the poll', async () => {
        render();

        for (const excluded of [
            { only: ['consultation'] },
            { prefetch: true },
            { meta: { silent: true } },
            { preserveState: true, preserveScroll: true },
            { showProgress: false },
        ]) {
            start(excluded);
            await wait(VERY_SLOW_AFTER_MS + 500);
            expect(overlay(), JSON.stringify(excluded)).toBeNull();
            finish(excluded);
        }
    });

    it('disappears immediately when the visit is cancelled or fails', async () => {
        render();
        start();
        await wait(SHOW_DELAY_MS + 10);
        expect(overlay()).not.toBeNull();

        finish({ cancelled: true });
        // No 450 ms minimum: only the leave transition's frame remains.
        await wait(50);
        expect(overlay()).toBeNull();

        start({ id: 'v2' });
        await wait(SHOW_DELAY_MS + 10);
        handlers.networkError({ detail: {} });
        await wait(50);
        expect(overlay()).toBeNull();
    });

    const percent = (): number =>
        Number.parseInt(
            document.querySelector('[data-testid="app-loader-percent"]')!
                .textContent!,
            10,
        );
    const barWidth = (): string =>
        document.querySelector<HTMLElement>('[data-testid="app-loader-bar"]')!
            .style.width;
    const arcLength = (): number =>
        Number.parseFloat(
            document
                .querySelector('[data-testid="app-loader-arc"]')!
                .getAttribute('stroke-dasharray')!,
        );
    const ARC = 2 * Math.PI * 74;

    it('restores the original preloader: tile, rings, badge, bilingual name, bar, footer', async () => {
        render('fr');
        start();
        await wait(SHOW_DELAY_MS + 10);

        const el = overlay()!;
        const text = el.textContent!;

        // The portal badge and status line follow the locale ...
        expect(text).toContain(
            "Portail Numérique Officiel des Droits d'Auteur",
        );
        expect(text).toContain("Chargement de l'espace ONDA...");
        // ... the institution's name is always in both languages ...
        expect(text).toContain('الديوان الوطني لحقوق المؤلف والحقوق المجاورة');
        expect(text).toContain(
            "Office National des Droits d'Auteur et des Droits Voisins",
        );
        // ... and so is the footer.
        expect(text).toContain('الجمهورية الجزائرية الديمقراطية الشعبية');
        expect(text).toContain('ONDA Algérie');

        // Logo tile, the progress arc with the original gradient, the dashed
        // ring, and the blue → teal bar.
        expect(el.querySelector('img')!.getAttribute('src')).toBe(
            '/assets/logos/logo.png',
        );
        expect(
            Array.from(el.querySelectorAll('linearGradient stop')).map((stop) =>
                stop.getAttribute('stop-color'),
            ),
        ).toEqual(['#1B669D', '#38bdf8', '#1C9976']);
        expect(
            el.querySelector('circle[stroke-dasharray="6 10"]'),
        ).not.toBeNull();
        expect(
            el.querySelector('[data-testid="app-loader-bar"]')!.className,
        ).toContain('from-onda-blue-500 via-sky-400 to-onda-teal-400');
    });

    it('trickles: the ring arc and the bar show the same value, never past 90 %', async () => {
        render();
        start();
        await wait(SHOW_DELAY_MS + 10);

        const first = percent();

        expect(first).toBeGreaterThan(0);
        expect(barWidth()).toBe(`${first}%`);
        expect(arcLength()).toBeCloseTo((ARC * first) / 100, 1);
        expect(
            document.querySelector<HTMLElement>(
                '[data-testid="app-loader-ring"]',
            )!.dataset.mode,
        ).toBe('trickle');

        await wait(60_000);

        expect(percent()).toBe(90);
        expect(barWidth()).toBe('90%');
        expect(arcLength()).toBeCloseTo(ARC * 0.9, 1);
    });

    it('shows the real progress of a form upload instead of the trickle', async () => {
        render();
        start({ method: 'post' });
        await wait(2_000);
        expect(percent()).toBeGreaterThan(40);

        handlers.progress({ detail: { progress: { percentage: 7 } } });
        await nextTick();

        expect(percent()).toBe(7);
        expect(barWidth()).toBe('7%');
        expect(arcLength()).toBeCloseTo(ARC * 0.07, 1);
        expect(
            document.querySelector<HTMLElement>(
                '[data-testid="app-loader-ring"]',
            )!.dataset.mode,
        ).toBe('real');

        await wait(5_000);
        expect(percent()).toBe(7);
    });

    it('completes to 100 % when the visit finishes', async () => {
        render();
        start();
        await wait(SHOW_DELAY_MS + 20);
        finish();
        await nextTick();

        expect(percent()).toBe(100);
        expect(barWidth()).toBe('100%');
    });

    it('renders the static variant under reduced motion', async () => {
        reducedMotion(true);
        render();
        start();
        await wait(SHOW_DELAY_MS + 10);

        expect(overlay()!.dataset.motion).toBe('reduced');
        expect(overlay()!.classList.contains('app-loader--static')).toBe(true);

        // No trickle animation: no moving number, an empty bar and the
        // original's fixed arc, however long the visit takes.
        await wait(5_000);

        expect(
            document.querySelector('[data-testid="app-loader-percent"]'),
        ).toBeNull();
        expect(barWidth()).toBe('0%');
        expect(arcLength()).toBe(140);

        // Real progress is information, not animation: it is still shown.
        handlers.progress({ detail: { progress: { percentage: 55 } } });
        await nextTick();

        expect(percent()).toBe(55);
    });

    it('renders the full-motion variant otherwise', async () => {
        render();
        start();
        await wait(SHOW_DELAY_MS + 10);

        expect(overlay()!.dataset.motion).toBe('full');
        expect(overlay()!.classList.contains('app-loader--static')).toBe(false);
    });

    it('follows the dark class of the document', async () => {
        render();
        start();
        await wait(SHOW_DELAY_MS + 10);
        expect(overlay()!.dataset.theme).toBe('light');
        finish();
        await wait(1_000);

        document.documentElement.classList.add('dark');
        start({ id: 'v2' });
        await wait(SHOW_DELAY_MS + 10);

        expect(overlay()!.dataset.theme).toBe('dark');
        // Light: the background token. Dark: the original's own backdrop.
        expect(overlay()!.className).toContain('bg-background/95');
        expect(overlay()!.className).toContain('dark:bg-[#070b14]/95');
        // The logo tile is dark in both themes, as designed.
        expect(
            overlay()!.querySelector('img')!.parentElement!.className,
        ).toContain('from-slate-900/90');
    });

    it('reads correctly in RTL: Arabic text, inherited direction, no physical sides', async () => {
        document.documentElement.setAttribute('dir', 'rtl');
        render('ar');
        start();
        await wait(SHOW_DELAY_MS + 10);

        expect(overlay()!.getAttribute('aria-label')).toBe('جارٍ التحميل…');
        expect(overlay()!.textContent).toContain(
            'جاري تحميل المنصة الرسمية...',
        );
        expect(overlay()!.textContent).toContain(
            'البوابة الرقمية لحقوق المؤلف والحقوق المجاورة',
        );
        expect(overlay()!.hasAttribute('dir')).toBe(false);

        // Nothing is pinned to a physical side except the two decorative
        // corner glows of the original design (symmetric, no content).
        const source = readFileSync(join(__dirname, 'AppLoader.vue'), 'utf8')
            .replace('-top-32 -left-32', '')
            .replace('-right-32 -bottom-32', '');

        expect(source).not.toMatch(
            /\b(?:left|right|ml|mr|pl|pr|text-left|text-right)-[\w[]/,
        );
    });

    it('retires the first-paint loader of app.blade.php when it mounts', async () => {
        document.body.insertAdjacentHTML(
            'afterbegin',
            '<div id="app-initial-loader" role="status"></div>',
        );

        render();

        const initial = document.getElementById('app-initial-loader')!;

        expect(initial.getAttribute('data-leaving')).toBe('true'); // fading

        await wait(300);

        expect(document.getElementById('app-initial-loader')).toBeNull();
    });
});
