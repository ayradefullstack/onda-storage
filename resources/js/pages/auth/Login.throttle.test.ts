// @vitest-environment happy-dom
import { HttpResponseError, http } from '@inertiajs/core';
import type { HttpResponse } from '@inertiajs/core';
import { App } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import type { DefineComponent } from 'vue';
import { createI18n } from 'vue-i18n';
import { useAuthStage } from '@/composables/useAuthStage';
import { pluralRules } from '@/lib/i18nPlural';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';
import Login from './Login.vue';

/**
 * POST /login answered with 429 (the IP request budget or the email+IP
 * limiter). This drives the REAL Inertia router and the REAL <Form> of
 * @inertiajs/vue3 over a fake HTTP client, so the response travels the same
 * path as in the browser: HttpResponseError -> Response.handle ->
 * handleNonInertiaResponse -> onHttpException -> finish. Only the transport
 * (XHR) is replaced, with the exact response shape Laravel produces for a
 * throttled request: status 429, an HTML body, lower-cased header names
 * (XhrHttpClient lower-cases them), and no `x-inertia` header.
 *
 * Not covered here, because happy-dom is not a browser: layout/CSS and the
 * real network.
 */
vi.mock('@/components/PasskeyVerify.vue', () => ({
    default: { render: () => null },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...((await importOriginal()) as Record<string, unknown>),
    Head: { render: () => null },
}));

type Outcome = {
    status: number;
    headers?: Record<string, string>;
    data?: string;
    /** Holds the response back until resolved (a slow, "late" response). */
    gate?: Promise<void>;
};

const outcomes: Outcome[] = [];
const requests: { url: string; method: string; data?: unknown }[] = [];

http.setClient({
    request: async (config) => {
        requests.push({
            url: config.url,
            method: config.method,
            data: config.data,
        });

        const next = outcomes.shift();

        if (!next) {
            throw new Error('no outcome queued for this request');
        }

        if (next.gate) {
            await next.gate;
        }

        const response: HttpResponse = {
            status: next.status,
            data: next.data ?? '',
            headers: next.headers ?? {},
        };

        if (next.status >= 400) {
            throw new HttpResponseError(
                `Request failed with status ${next.status}`,
                response,
                config.url,
            );
        }

        return response;
    },
});

/** What Laravel's ThrottleRequests sends for a blocked POST /login. */
const throttled = (retryAfter?: string): Outcome => ({
    status: 429,
    headers: {
        'content-type': 'text/html; charset=UTF-8',
        'x-ratelimit-limit': '20',
        'x-ratelimit-remaining': '0',
        ...(retryAfter === undefined
            ? {}
            : { 'retry-after': retryAfter, 'x-ratelimit-reset': '1790000000' }),
    },
    data: '<!DOCTYPE html><html><head><title>Too Many Requests</title></head><body>429 | Too Many Requests</body></html>',
});

/**
 * The login middleware's own "busy" 429: text/plain, discriminated ONLY by
 * x-login-reason. It carries the same retry-after / rate-limit headers a real
 * response has, which is exactly why they must not drive the wording.
 */
const busy = (retryAfter = '2'): Outcome => ({
    status: 429,
    headers: {
        'content-type': 'text/plain; charset=UTF-8',
        'x-login-reason': 'busy',
        'retry-after': retryAfter,
        'x-ratelimit-limit': '20',
        'x-ratelimit-remaining': '19',
    },
    data: SENTINEL,
});

/** The middleware's account lockout 429. */
const locked = (
    opts: {
        lockedUntil?: string;
        serverTime?: string;
        retryAfter?: string;
        reason?: boolean;
    } = {},
): Outcome => ({
    status: 429,
    headers: {
        'content-type': 'application/json',
        ...(opts.reason === false ? {} : { 'x-login-reason': 'locked' }),
        ...(opts.retryAfter === undefined
            ? {}
            : { 'retry-after': opts.retryAfter }),
        ...(opts.lockedUntil === undefined
            ? {}
            : { 'x-locked-until': opts.lockedUntil }),
        ...(opts.serverTime === undefined
            ? {}
            : { 'x-server-time': opts.serverTime }),
    },
    data: JSON.stringify({ message: SENTINEL, reason: 'locked' }),
});

/** A 429 whose headers match no known contract. */
const unrecognized = (headers: Record<string, string> = {}): Outcome => ({
    status: 429,
    headers: { 'content-type': 'text/html; charset=UTF-8', ...headers },
    data: SENTINEL,
});

/** An Inertia page response, i.e. what a followed redirect ends in. */
const inertiaPage = (
    component: string,
    props: Record<string, unknown> = {},
    url = '/',
): Outcome => ({
    status: 200,
    headers: {
        'content-type': 'application/json',
        'x-inertia': 'true',
        vary: 'X-Inertia',
    },
    data: JSON.stringify({
        component,
        props: { errors: {}, ...props },
        url,
        version: 'v1',
    }),
});

const TwoFactorStub = defineComponent({
    render: () => h('div', { 'data-test': 'two-factor-page' }),
});
const DashboardStub = defineComponent({
    render: () => h('div', { 'data-test': 'dashboard-page' }),
});

type Locale = 'fr' | 'en' | 'ar';
type Translate = (
    key: string,
    named?: Record<string, unknown>,
    plural?: number,
) => string;

/**
 * Same i18n configuration the page is mounted with (real Arabic plural rule),
 * used to DERIVE the expected wording from the locale bundles so a wording
 * tweak does not break the tests. Per-language differences are still pinned
 * by literal fragments in the tests themselves.
 */
const makeI18n = (locale: Locale) =>
    createI18n({
        legacy: false,
        locale,
        messages: { en, fr, ar },
        pluralRules,
        missingWarn: false,
        fallbackWarn: false,
    });

const translator = (locale: Locale): Translate =>
    makeI18n(locale).global.t as unknown as Translate;

const duration = (
    locale: Locale,
    key: 'durationSeconds' | 'durationMinutes' | 'durationHours',
    count: number,
): string => translator(locale)(`authUi.err.${key}`, { count }, count);

/** "Try again in {duration}" messages, derived from the bundles. */
const tooManyFor = (locale: Locale, dur: string): string =>
    translator(locale)('authUi.err.tooManyFor', { duration: dur });
const lockedFor = (locale: Locale, dur: string): string =>
    translator(locale)('authUi.err.lockedFor', { duration: dur });
const message = (
    locale: Locale,
    key: 'loginBusy' | 'lockedNoDelay' | 'tooManyNoDelay',
): string => translator(locale)(`authUi.err.${key}`);

const SENTINEL = 'SENTINEL-SERVER-BODY-DO-NOT-RENDER';

let wrapper: VueWrapper | null = null;

function mountLogin(locale: 'fr' | 'en' | 'ar' = 'fr'): VueWrapper {
    wrapper = mount(App, {
        props: {
            initialPage: {
                component: 'auth/Login',
                props: { errors: {}, canResetPassword: true },
                url: '/login',
                version: 'v1',
                rescuedProps: [],
                flash: {},
                rememberedState: {},
            },
            initialComponent: Login as unknown as DefineComponent,
            resolveComponent: async (name: string) =>
                ({
                    'auth/Login': Login,
                    'auth/TwoFactorChallenge': TwoFactorStub,
                    'author/Dashboard': DashboardStub,
                })[name] as unknown as DefineComponent,
            serverRendered: false,
        },
        global: {
            plugins: [
                createI18n({
                    legacy: false,
                    locale,
                    messages: { en, fr, ar },
                    pluralRules,
                    missingWarn: false,
                    fallbackWarn: false,
                }),
            ],
        },
        attachTo: document.body,
    });

    return wrapper;
}

async function submit(
    view: VueWrapper,
    email = 'author@example.com',
): Promise<void> {
    await view.find('#login-email').setValue(email);
    await view.find('#login-password').setValue('a-password');
    await view.find('form').trigger('submit');

    await vi.waitFor(() => expect(outcomes).toHaveLength(0));
    await flushPromises();
}

const submitButton = (view: VueWrapper) => view.find('button[type="submit"]');
const errorDialog = () => document.getElementById('inertia-error-dialog');

/**
 * Inertia fires a cancelable `inertia:httpException` event, and only AFTER the
 * form's own onHttpException returned something other than false; it then
 * shows its default error dialog unless the event is cancelled. Observing it
 * (and cancelling it, because happy-dom cannot load the dialog's iframe) is
 * how the test tells "left to Inertia" from "handled by the page".
 */
const inertiaDefaultHandling: number[] = [];
const onInertiaHttpException = (event: Event): void => {
    inertiaDefaultHandling.push(
        (event as CustomEvent<{ response: { status: number } }>).detail.response
            .status,
    );
    event.preventDefault();
};

// Test-environment shim only. Inertia's <Form> keeps a FormData in a Vue ref;
// Vue proxies any object whose toString tag it does not recognise, and
// happy-dom's FormData (private fields) breaks behind a proxy. Real
// browsers expose the 'FormData' tag, so Vue leaves it alone there.
Object.defineProperty(FormData.prototype, Symbol.toStringTag, {
    value: 'FormData',
    configurable: true,
});

beforeEach(() => {
    outcomes.length = 0;
    requests.length = 0;
    inertiaDefaultHandling.length = 0;
    document.addEventListener('inertia:httpException', onInertiaHttpException);
    useAuthStage().setStageState('idle');

    // happy-dom's <dialog> may lack showModal(); Inertia's default error
    // dialog (the thing this test must NOT see for a 429) calls it.
    HTMLDialogElement.prototype.showModal ??= function showModal() {};
});

afterEach(() => {
    document.removeEventListener(
        'inertia:httpException',
        onInertiaHttpException,
    );
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    document.getElementById('inertia-error-dialog')?.remove();
});

describe('Login — throttled (429) submission', () => {
    it.each(['fr', 'en', 'ar'] as const)(
        'shows the localized inline error in %s and no error dialog',
        async (locale) => {
            const view = mountLogin(locale);
            outcomes.push(throttled('42'));

            await submit(view);

            expect(requests).toHaveLength(1);
            expect(new URL(requests[0].url).pathname).toBe('/login');
            expect(requests[0].method).toBe('post');
            const expected = tooManyFor(
                locale,
                duration(locale, 'durationSeconds', 42),
            );
            expect(view.text()).toContain(expected);
            expect(view.find('[role="alert"]').text()).toContain(expected);
            // Inertia's default for an HTML error response is a dialog with
            // the raw server page; the handler returning false suppresses it
            // (the global httpException event never fires).
            expect(inertiaDefaultHandling).toEqual([]);
            expect(errorDialog()).toBeNull();
        },
    );

    it('falls back to the no-delay message without a usable Retry-After', async () => {
        const view = mountLogin('en');
        outcomes.push(throttled());

        await submit(view);

        expect(view.text()).toContain(message('en', 'tooManyNoDelay'));
        expect(view.text()).not.toContain('NaN');
        expect(errorDialog()).toBeNull();
    });

    it('clears the submitting state', async () => {
        const view = mountLogin('en');
        outcomes.push(throttled('42'));

        await submit(view);

        const button = submitButton(view);
        expect(button.attributes('data-state')).toBe('idle');
        expect(button.attributes('aria-busy')).toBeUndefined();
        expect(button.attributes('aria-disabled')).toBeUndefined();
        expect(useAuthStage().stageState.value).toBe('idle');
    });

    it('lets the user retry, and the retry is a real second request', async () => {
        const view = mountLogin('en');
        outcomes.push(throttled('42'));
        await submit(view);
        expect(view.text()).toContain('Too many sign-in attempts');

        // Second attempt: the server now answers a normal wrong-credentials
        // page (Fortify's redirect back with an `email` error).
        outcomes.push(
            inertiaPage(
                'auth/Login',
                {
                    canResetPassword: true,
                    errors: {
                        email: 'These credentials do not match our records.',
                    },
                },
                '/login',
            ),
        );
        await submit(view);

        expect(requests).toHaveLength(2);
        expect(view.text()).toContain(
            'These credentials do not match our records.',
        );
        expect(view.text()).not.toContain('Too many sign-in attempts');
        expect(errorDialog()).toBeNull();
    });
});

async function alertAfter(
    locale: Locale,
    outcome: Outcome,
): Promise<{ view: VueWrapper; alert: string }> {
    const view = mountLogin(locale);
    outcomes.push(outcome);

    await submit(view);

    return { view, alert: view.find('[role="alert"]').text() };
}

const expectNoDialogNoNaN = (view: VueWrapper): void => {
    expect(view.text()).not.toContain('NaN');
    expect(view.text()).not.toContain('undefined');
    expect(inertiaDefaultHandling).toEqual([]);
    expect(errorDialog()).toBeNull();
};

describe('Login — busy vs request throttle (same Retry-After)', () => {
    it.each(['fr', 'en', 'ar'] as const)(
        'busy shows the busy wording with no number in %s',
        async (locale) => {
            const { view, alert } = await alertAfter(locale, busy('2'));

            expect(alert).toContain(message(locale, 'loginBusy'));
            // Busy never shows a delay, whatever Retry-After says.
            expect(alert).not.toMatch(/[0-9٠-٩]/);
            expectNoDialogNoNaN(view);
        },
    );

    it.each([
        ['fr', 'Réessayez dans 2 secondes.'],
        ['en', 'Try again in 2 seconds.'],
        ['ar', 'بعد ثانيتين.'],
    ] as const)(
        'the throttle with the SAME retry-after shows the delay in %s',
        async (locale, fragment) => {
            const { view, alert } = await alertAfter(locale, throttled('2'));

            expect(alert).toContain(fragment);
            expect(alert).toContain(
                tooManyFor(locale, duration(locale, 'durationSeconds', 2)),
            );
            expectNoDialogNoNaN(view);
        },
    );

    it.each(['fr', 'en', 'ar'] as const)(
        'busy and throttle messages differ in %s',
        async (locale) => {
            const first = await alertAfter(locale, busy('2'));
            const busyText = first.alert;
            wrapper?.unmount();
            wrapper = null;
            document.body.innerHTML = '';

            const second = await alertAfter(locale, throttled('2'));

            expect(busyText).not.toBe(second.alert);
            expect(message(locale, 'loginBusy')).not.toBe(
                tooManyFor(locale, duration(locale, 'durationSeconds', 2)),
            );
        },
    );

    it('busy never claims a lockout or a wrong password', async () => {
        const { alert } = await alertAfter('en', busy('2'));

        expect(alert).not.toMatch(/locked|too many|incorrect|do not match/i);
    });
});

describe('Login — account lockout (429, x-login-reason: locked)', () => {
    const now = '2026-11-19T10:00:00Z';

    it.each([
        ['en', '7 minutes'],
        ['fr', '7 minutes'],
        ['ar', '7 دقائق'],
    ] as const)(
        'non-default duration comes from the server clock difference in %s, not Retry-After',
        async (locale, fragment) => {
            const { view, alert } = await alertAfter(
                locale,
                locked({
                    serverTime: now,
                    lockedUntil: '2026-11-19T10:07:00Z',
                    retryAfter: '999', // deliberately inconsistent
                }),
            );

            expect(alert).toContain(fragment);
            expect(alert).toContain(
                lockedFor(locale, duration(locale, 'durationMinutes', 7)),
            );
            expect(alert).not.toContain('999');
            expectNoDialogNoNaN(view);
        },
    );

    it.each([
        ['en', '2 minutes'],
        ['fr', '2 minutes'],
        ['ar', 'دقيقتين'],
    ] as const)(
        'falls back to Retry-After when x-server-time is missing in %s',
        async (locale, fragment) => {
            const { view, alert } = await alertAfter(
                locale,
                locked({
                    lockedUntil: '2026-11-19T10:07:00Z',
                    retryAfter: '120',
                }),
            );

            expect(alert).toContain(fragment);
            expect(alert).toContain(
                lockedFor(locale, duration(locale, 'durationMinutes', 2)),
            );
            expectNoDialogNoNaN(view);
        },
    );

    it('falls back to Retry-After when the dates are unparseable', async () => {
        const { alert } = await alertAfter(
            'en',
            locked({
                serverTime: 'garbage',
                lockedUntil: 'also garbage',
                retryAfter: '120',
            }),
        );

        expect(alert).toContain('2 minutes');
        expect(alert).not.toContain('NaN');
    });

    it('x-locked-until alone (no reason header) is still a lockout', async () => {
        const { alert } = await alertAfter(
            'en',
            locked({
                reason: false,
                serverTime: now,
                lockedUntil: '2026-11-19T10:07:00Z',
            }),
        );

        expect(alert).toContain(
            lockedFor('en', duration('en', 'durationMinutes', 7)),
        );
        // Lockout wording, not the throttle wording.
        expect(alert).not.toContain(
            tooManyFor('en', duration('en', 'durationMinutes', 7)),
        );
    });

    it('a lockout without any usable delay uses the no-delay lockout message', async () => {
        const { view, alert } = await alertAfter('en', locked());

        expect(alert).toContain(message('en', 'lockedNoDelay'));
        expectNoDialogNoNaN(view);
    });

    it.each([
        ['en', '1 hour and 30 minutes'],
        ['fr', '1 heure et 30 minutes'],
        ['ar', 'ساعة واحدة و30 دقيقة'],
    ] as const)(
        '5400 s is shown as hours and minutes in %s',
        async (locale, fragment) => {
            const { alert } = await alertAfter(
                locale,
                locked({
                    serverTime: now,
                    lockedUntil: '2026-11-19T11:30:00Z',
                }),
            );

            expect(alert).toContain(fragment);
        },
    );

    it.each([
        ['en', 'Try again in 30 minutes.'],
        ['fr', 'Réessayez dans 30 minutes.'],
        ['ar', 'بعد 30 دقيقة.'],
    ] as const)(
        'the default 1800 s lockout reads as 30 minutes in %s',
        async (locale, fragment) => {
            const { view, alert } = await alertAfter(
                locale,
                locked({
                    serverTime: now,
                    lockedUntil: '2026-11-19T10:30:00Z',
                    retryAfter: '1800',
                }),
            );

            expect(alert).toContain(fragment);
            expect(alert).toContain(
                lockedFor(locale, duration(locale, 'durationMinutes', 30)),
            );
            expectNoDialogNoNaN(view);
        },
    );

    it('a lockout is not worded like the request throttle', async () => {
        const lockout = await alertAfter(
            'en',
            locked({ serverTime: now, lockedUntil: '2026-11-19T10:30:00Z' }),
        );

        expect(lockout.alert).toContain(
            lockedFor('en', duration('en', 'durationMinutes', 30)),
        );
        expect(lockout.alert).not.toContain(
            tooManyFor('en', duration('en', 'durationMinutes', 30)),
        );
    });
});

describe('Login — throttle seconds use the real plural forms', () => {
    it.each([
        [1, 'ثانية واحدة'],
        [2, 'ثانيتين'],
        [3, '3 ثوانٍ'],
        [10, '10 ثوانٍ'],
        [11, '11 ثانية'],
    ] as const)('Arabic, %i s -> %s', async (seconds, fragment) => {
        const { alert } = await alertAfter('ar', throttled(String(seconds)));

        expect(alert).toContain(`بعد ${fragment}`);
    });

    it.each([
        [1, '1 second'],
        [42, '42 seconds'],
    ] as const)('English, %i s -> %s', async (seconds, fragment) => {
        const { alert } = await alertAfter('en', throttled(String(seconds)));

        expect(alert).toContain(`Try again in ${fragment}.`);
    });

    it.each([
        [1, '1 seconde'],
        [42, '42 secondes'],
    ] as const)('French, %i s -> %s', async (seconds, fragment) => {
        const { alert } = await alertAfter('fr', throttled(String(seconds)));

        expect(alert).toContain(`Réessayez dans ${fragment}.`);
    });
});

describe('Login — unrecognized 429', () => {
    it('an unknown x-login-reason is treated as a throttle and uses Retry-After', async () => {
        const { view, alert } = await alertAfter(
            'en',
            unrecognized({ 'x-login-reason': 'other', 'retry-after': '30' }),
        );

        expect(alert).toContain(
            tooManyFor('en', duration('en', 'durationSeconds', 30)),
        );
        expect(alert).toContain('30 seconds');
        expectNoDialogNoNaN(view);
    });

    it('no headers at all uses the no-delay throttle message', async () => {
        const { view, alert } = await alertAfter('en', unrecognized());

        expect(alert).toContain(message('en', 'tooManyNoDelay'));
        expectNoDialogNoNaN(view);
    });

    it.each(['0', '-5', 'abc', '1.5', '', 'Wed, 21 Oct 2026 07:28:00 GMT'])(
        'an unusable Retry-After (%j) gives the no-delay message, never NaN',
        async (retryAfter) => {
            const { view, alert } = await alertAfter(
                'en',
                unrecognized({ 'retry-after': retryAfter }),
            );

            expect(alert).toContain(message('en', 'tooManyNoDelay'));
            expectNoDialogNoNaN(view);
        },
    );

    it.each([
        ['busy', busy('2')],
        ['locked', locked({ retryAfter: '60' })],
        ['throttle', throttled('2')],
        ['unrecognized', unrecognized({ 'retry-after': '5' })],
    ] as const)(
        'the %s server body is never rendered',
        async (_name, outcome) => {
            const { view } = await alertAfter('en', outcome);

            expect(view.text()).not.toContain(SENTINEL);
            expect(document.body.innerHTML).not.toContain(SENTINEL);
        },
    );
});

describe('Login — the handler does not intercept anything else', () => {
    it.each([419, 500, 503])(
        'leaves a %i to Inertia (default handling runs, no inline throttle message)',
        async (status) => {
            const view = mountLogin('en');
            outcomes.push({
                status,
                headers: { 'content-type': 'text/html; charset=UTF-8' },
                data: '<html><body>server page</body></html>',
            });

            await submit(view);

            expect(inertiaDefaultHandling).toEqual([status]);
            expect(view.text()).not.toContain('Too many sign-in attempts');
        },
    );

    it('a 503 carrying x-login-reason: busy is still left to Inertia (only 429 is classified)', async () => {
        const view = mountLogin('en');
        outcomes.push({
            status: 503,
            headers: {
                'content-type': 'text/plain; charset=UTF-8',
                'x-login-reason': 'busy',
                'retry-after': '2',
            },
            data: 'busy',
        });

        await submit(view);

        expect(inertiaDefaultHandling).toEqual([503]);
        expect(view.text()).not.toContain(message('en', 'loginBusy'));
    });

    it('a successful login redirect is untouched', async () => {
        const view = mountLogin('en');
        outcomes.push(inertiaPage('author/Dashboard', {}, '/author/dashboard'));

        await submit(view);

        expect(view.find('[data-test="dashboard-page"]').exists()).toBe(true);
        expect(view.text()).not.toContain('Too many sign-in attempts');
        expect(errorDialog()).toBeNull();
        expect(useAuthStage().stageState.value).toBe('stamped');
    });

    it('the redirect to the two-factor challenge is untouched', async () => {
        const view = mountLogin('en');
        outcomes.push(
            inertiaPage('auth/TwoFactorChallenge', {}, '/two-factor-challenge'),
        );

        await submit(view);

        expect(view.find('[data-test="two-factor-page"]').exists()).toBe(true);
        expect(view.text()).not.toContain('Too many sign-in attempts');
        expect(errorDialog()).toBeNull();
        expect(useAuthStage().stageState.value).toBe('stamped');
    });

    it('an ordinary wrong-password response still reaches the field error', async () => {
        const view = mountLogin('en');
        outcomes.push(
            inertiaPage(
                'auth/Login',
                {
                    canResetPassword: true,
                    errors: {
                        email: 'These credentials do not match our records.',
                    },
                },
                '/login',
            ),
        );

        await submit(view);

        expect(view.text()).toContain(
            'These credentials do not match our records.',
        );
        expect(view.text()).not.toContain('Too many sign-in attempts');
    });
});

// ---------------------------------------------------------------------------
// Account lockout countdown + optional bell (phase 4)
//
// Same real Inertia router / <Form>; only the transport is faked. Time is
// faked AFTER mounting and BEFORE submitting, and only for what the countdown
// reads (setInterval, Date, performance): Inertia's own setTimeout stays real,
// so requests still resolve. Because vi.waitFor advances fake timers by itself,
// these tests use their own settle() built on the real setTimeout.
// ---------------------------------------------------------------------------

const LOCK_BASE = '2026-11-19T10:00:00Z';

/** A locked 429 whose server-clock difference is `seconds`. */
const lockedIn = (seconds: number, retryAfter?: string): Outcome =>
    locked({
        serverTime: LOCK_BASE,
        lockedUntil: new Date(Date.parse(LOCK_BASE) + seconds * 1000)
            .toISOString()
            .replace('.000Z', 'Z'),
        retryAfter: retryAfter ?? String(seconds),
    });

const WHO_A = 'author@example.com';
const WHO_B = 'other@example.com';

const wrongPassword = (): Outcome =>
    inertiaPage(
        'auth/Login',
        {
            canResetPassword: true,
            errors: { email: 'These credentials do not match our records.' },
        },
        '/login',
    );

type AudioMode = 'ok' | 'reject' | 'suspended';
let audioMode: AudioMode = 'ok';

type FakeOsc = {
    type: string;
    frequency: { value: number; setValueAtTime: (v: number) => void };
    connect: ReturnType<typeof vi.fn>;
    start: ReturnType<typeof vi.fn>;
    stop: ReturnType<typeof vi.fn>;
};

class FakeAudioContext {
    static instances: FakeAudioContext[] = [];

    state = 'suspended';
    currentTime = 0;
    destination = {};
    oscillators: FakeOsc[] = [];

    resume = vi.fn(async () => {
        if (audioMode === 'reject') {
            throw new DOMException('blocked', 'NotAllowedError');
        }

        if (audioMode === 'ok') {
            this.state = 'running';
        }
    });
    close = vi.fn(async () => {
        this.state = 'closed';
    });
    createOscillator = vi.fn(() => {
        const osc: FakeOsc = {
            type: 'sine',
            frequency: {
                value: 0,
                setValueAtTime: (v: number) => {
                    osc.frequency.value = v;
                },
            },
            connect: vi.fn((node: unknown) => node),
            start: vi.fn(),
            stop: vi.fn(),
        };
        this.oscillators.push(osc);

        return osc;
    });
    createGain = vi.fn(() => ({
        gain: {
            value: 1,
            setValueAtTime: vi.fn(),
            exponentialRampToValueAtTime: vi.fn(),
            linearRampToValueAtTime: vi.fn(),
            cancelScheduledValues: vi.fn(),
        },
        connect: vi.fn((node: unknown) => node),
    }));

    constructor() {
        FakeAudioContext.instances.push(this);
    }
}

/** Must run BEFORE mounting: the bell resolves the constructor lazily. */
function installAudio(mode: AudioMode = 'ok'): void {
    audioMode = mode;
    FakeAudioContext.instances.length = 0;
    vi.stubGlobal('AudioContext', FakeAudioContext);
}

const pause = (ms = 15): Promise<void> =>
    new Promise((resolve) => setTimeout(resolve, ms));

/** Wait on the REAL setTimeout (fake timers must not be advanced by polling). */
async function settle(done: () => boolean = () => true): Promise<void> {
    for (let i = 0; i < 200 && !done(); i++) {
        await pause(5);
    }

    await pause(10);
    await flushPromises();
    await nextTick();
}

/** Mount, THEN fake time (the order the production page experiences). */
function mountFaked(locale: Locale = 'en'): VueWrapper {
    const view = mountLogin(locale);
    vi.useFakeTimers({
        toFake: ['setInterval', 'clearInterval', 'Date', 'performance'],
    });

    return view;
}

async function fillAndSubmit(view: VueWrapper, email: string): Promise<void> {
    await view.find('#login-email').setValue(email);
    await view.find('#login-password').setValue('a-password');
    await view.find('form').trigger('submit');
}

/** Submit and wait until the queued response has been handled. */
async function submitFaked(view: VueWrapper, email = WHO_A): Promise<void> {
    await fillAndSubmit(view, email);
    await settle(() => outcomes.length === 0);
}

/** Submit something that must NOT reach the network. */
async function submitBlocked(view: VueWrapper, email = WHO_A): Promise<void> {
    await fillAndSubmit(view, email);
    await settle();
}

/** Let `ms` of fake time pass (interval ticks fire, Date/performance move). */
async function advance(ms: number): Promise<void> {
    vi.advanceTimersByTime(ms);
    await nextTick();
    await flushPromises();
}

const notice = (view: VueWrapper) => view.find('[data-test="lockout-notice"]');
const timer = (view: VueWrapper) => view.find('[data-test="lockout-timer"]');
const status = (view: VueWrapper) => view.find('[data-test="lockout-status"]');
const blockedHint = (view: VueWrapper) =>
    view.find('[data-test="lockout-sound-blocked"]');
const soundToggle = (view: VueWrapper) => view.find('#lockout-sound');
const loginButton = (view: VueWrapper) =>
    view.find('[data-test="login-button"]');
const alertText = (view: VueWrapper): string => {
    const el = view.find('[role="alert"]');

    return el.exists() ? el.text() : '';
};
const occurrences = (haystack: string, needle: string): number =>
    haystack.split(needle).length - 1;

const readyText = (locale: Locale): string =>
    translator(locale)('authUi.lockout.ready');

/** The click must reach the handler SYNCHRONOUSLY (user-gesture rules). */
function clickSoundSync(): void {
    document
        .getElementById('lockout-sound')!
        .dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );
}

function postedKeys(data: unknown): string[] {
    if (data instanceof FormData) {
        return Array.from(data.keys());
    }

    if (typeof data === 'string') {
        return [data];
    }

    if (data && typeof data === 'object') {
        return Object.keys(data);
    }

    return [];
}

const storageSnapshot = (): string =>
    JSON.stringify([
        { ...localStorage },
        { ...sessionStorage },
        document.cookie,
    ]);

describe('Login — lockout countdown', () => {
    afterEach(() => {
        // Unmount while the fake clock is still installed so dispose()
        // clears the fake interval it created.
        wrapper?.unmount();
        wrapper = null;
        vi.useRealTimers();
        vi.unstubAllGlobals();
        FakeAudioContext.instances.length = 0;
    });

    describe('when it starts', () => {
        it('shows the countdown for a non-default 7-minute lockout (server clock, not Retry-After)', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420, '999'));

            await submitFaked(view);

            expect(notice(view).exists()).toBe(true);
            expect(timer(view).text()).toBe('07:00');
            expect(timer(view).text()).not.toContain('999');
            // The existing alert is the one initial announcement.
            expect(alertText(view)).toContain(
                lockedFor('en', duration('en', 'durationMinutes', 7)),
            );
            expect(alertText(view)).toContain('7 minutes');
            expect(submitButton(view).attributes('aria-disabled')).toBe('true');
        });

        it.each([
            [1800, '30:00'],
            [5400, '1:30:00'],
            [90, '01:30'],
            [3599, '59:59'],
        ])('a %i s lockout starts at %s', async (seconds, text) => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(seconds));

            await submitFaked(view);

            expect(timer(view).text()).toBe(text);
        });

        it('shows the labels from the bundle and pins the English wording', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420));

            await submitFaked(view);

            const label = translator('en')('authUi.lockout.remaining');
            expect(label).toBe('Time remaining');
            expect(notice(view).text()).toContain(label);
            expect(view.text()).toContain(
                translator('en')('authUi.lockout.sound'),
            );
            expect(translator('en')('authUi.lockout.sound')).toBe(
                'Play a sound when I can try again',
            );
            expect(translator('en')('authUi.lockout.soundHint')).toBe(
                'Only while this page stays open.',
            );
        });

        it('counts down with ELAPSED time', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420));
            await submitFaked(view);

            await advance(1_000);
            expect(timer(view).text()).toBe('06:59');

            await advance(60_000);
            expect(timer(view).text()).toBe('05:59');
        });

        it('keeps exactly one interval while counting and none once it expired', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view);
            expect(vi.getTimerCount()).toBe(1);

            await advance(61_000);

            expect(vi.getTimerCount()).toBe(0);
        });
    });

    describe('what does NOT start it', () => {
        it.each([
            ['busy', () => busy('2')],
            ['throttle', () => throttled('42')],
            ['unrecognized', () => unrecognized({ 'retry-after': '5' })],
            ['locked without any delay', () => locked()],
        ] as const)(
            '%s: no notice, no sound toggle, no AudioContext, button not aria-disabled',
            async (_name, outcome) => {
                installAudio();
                const view = mountLogin('en');
                outcomes.push(outcome());

                await submit(view);

                expect(notice(view).exists()).toBe(false);
                expect(soundToggle(view).exists()).toBe(false);
                expect(FakeAudioContext.instances).toHaveLength(0);
                expect(
                    submitButton(view).attributes('aria-disabled'),
                ).toBeUndefined();
                expect(status(view).text()).toBe('');
                // The alert behaviour is unchanged for all of them.
                expect(alertText(view)).not.toBe('');
            },
        );

        it('a no-delay lockout after expiry removes the stale entry and keeps the button enabled', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view);
            await advance(61_000);

            outcomes.push(locked());
            await submitFaked(view);

            expect(requests).toHaveLength(2);
            expect(notice(view).exists()).toBe(false);
            expect(
                submitButton(view).attributes('aria-disabled'),
            ).toBeUndefined();
            expect(alertText(view)).toContain(message('en', 'lockedNoDelay'));
        });
    });

    describe('while locked', () => {
        it('the submit button is aria-disabled, described by the sr-only text, and a submit sends nothing', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420));
            await submitFaked(view);
            expect(requests).toHaveLength(1);

            expect(loginButton(view).attributes('aria-disabled')).toBe('true');
            expect(loginButton(view).attributes('disabled')).toBeUndefined();
            expect(loginButton(view).attributes('aria-describedby')).toBe(
                'lockout-desc',
            );
            const desc = view.find('#lockout-desc');
            expect(desc.exists()).toBe(true);
            expect(desc.classes()).toContain('sr-only');
            expect(desc.text()).toContain(
                lockedFor('en', duration('en', 'durationMinutes', 7)),
            );

            // Form submit (what Enter in a field does) and a button click.
            await submitBlocked(view);
            await loginButton(view).trigger('click');
            await settle();

            expect(requests).toHaveLength(1);
            expect(view.text()).not.toContain('NaN');
        });

        it('keeps the passkey area present and nothing in it disabled', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420));
            await submitFaked(view);

            expect(view.find('.auth-passkey').exists()).toBe(true);
            expect(
                view.findAll(
                    '.auth-passkey [disabled], .auth-passkey [aria-disabled="true"]',
                ),
            ).toHaveLength(0);
            expect(
                view.find('.auth-passkey').attributes('aria-disabled'),
            ).toBeUndefined();
        });

        it('a 422 for ANOTHER address does not disturb the saved countdown', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420));
            await submitFaked(view, WHO_A);

            outcomes.push(wrongPassword());
            await submitFaked(view, WHO_B);
            expect(requests).toHaveLength(2);

            await view.find('#login-email').setValue(WHO_A);
            await nextTick();

            expect(notice(view).exists()).toBe(true);
            expect(timer(view).text()).toBe('07:00');
        });
    });

    describe('at expiry', () => {
        it.each([
            ['en', 'You can try again now.'],
            ['fr', 'Vous pouvez réessayer maintenant.'],
            ['ar', 'يمكنك إعادة المحاولة الآن.'],
        ] as const)(
            'announces readiness once in %s, enables the button and moves no focus',
            async (locale, literal) => {
                installAudio();
                const view = mountFaked(locale);
                outcomes.push(lockedIn(60));
                await submitFaked(view);
                expect(timer(view).text()).toBe('01:00');
                const focused = document.activeElement;

                await advance(61_000);

                expect(readyText(locale)).toBe(literal);
                const live = status(view);
                expect(live.attributes('role')).toBe('status');
                expect(occurrences(live.text(), literal)).toBe(1);
                expect(alertText(view)).toBe('');
                expect(timer(view).exists()).toBe(false);
                expect(
                    submitButton(view).attributes('aria-disabled'),
                ).toBeUndefined();
                expect(document.activeElement).toBe(focused);

                // More time and a catch-up event: still announced once, and
                // no automatic submit.
                await advance(30_000);
                document.dispatchEvent(new Event('visibilitychange'));
                await nextTick();
                expect(occurrences(status(view).text(), literal)).toBe(1);
                expect(requests).toHaveLength(1);
            },
        );

        it('the live region never carries digits while the timer ticks', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420));
            await submitFaked(view);
            const initialAlert = alertText(view);

            for (let i = 0; i < 5; i++) {
                await advance(1_000);
                expect(status(view).text()).toBe('');
                expect(status(view).text()).not.toMatch(/[0-9٠-٩]/);
            }

            // The alert is not re-announced every second either.
            expect(alertText(view)).toBe(initialAlert);
        });

        it('the next submit clears the ready message; a new lockout starts a fresh countdown and announces again', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view);
            await advance(61_000);
            expect(status(view).text()).toContain(readyText('en'));

            outcomes.push(lockedIn(120));
            await submitFaked(view);

            expect(requests).toHaveLength(2);
            expect(status(view).text()).not.toContain(readyText('en'));
            expect(notice(view).exists()).toBe(true);
            expect(timer(view).text()).toBe('02:00');
            expect(submitButton(view).attributes('aria-disabled')).toBe('true');

            await advance(121_000);

            expect(occurrences(status(view).text(), readyText('en'))).toBe(1);
            expect(timer(view).exists()).toBe(false);
        });

        it('a retry that gets a wrong-password page after expiry clears the countdown state', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view);
            await advance(61_000);

            outcomes.push(wrongPassword());
            await submitFaked(view);

            expect(requests).toHaveLength(2);
            expect(view.text()).toContain(
                'These credentials do not match our records.',
            );
            expect(notice(view).exists()).toBe(false);
            expect(status(view).text()).toBe('');
        });
    });

    describe('switching the address', () => {
        it('enables submit for another address and shows the remaining time when switching back', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420));
            await submitFaked(view, WHO_A);

            await view.find('#login-email').setValue(WHO_B);
            await nextTick();
            expect(notice(view).exists()).toBe(false);
            expect(
                submitButton(view).attributes('aria-disabled'),
            ).toBeUndefined();

            // The other address can really be submitted meanwhile.
            outcomes.push(wrongPassword());
            await submitFaked(view, WHO_B);
            expect(requests).toHaveLength(2);

            await advance(60_000);
            await view.find('#login-email').setValue(WHO_A);
            await nextTick();

            expect(notice(view).exists()).toBe(true);
            expect(timer(view).text()).toBe('06:00');
            expect(submitButton(view).attributes('aria-disabled')).toBe('true');
        });

        it('matches the address regardless of case and surrounding spaces', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420));
            await submitFaked(view, WHO_A);

            await view.find('#login-email').setValue(WHO_B);
            await nextTick();
            await view.find('#login-email').setValue('  AUTHOR@Example.COM ');
            await nextTick();

            expect(notice(view).exists()).toBe(true);
            expect(timer(view).text()).toBe('07:00');
        });

        it('an entry that expires while another address is shown is never announced', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view, WHO_A);

            await view.find('#login-email').setValue(WHO_B);
            await nextTick();
            await advance(120_000);
            await view.find('#login-email').setValue(WHO_A);
            await nextTick();

            expect(notice(view).exists()).toBe(false);
            expect(status(view).text()).toBe('');
            expect(
                submitButton(view).attributes('aria-disabled'),
            ).toBeUndefined();
        });
    });

    describe('a late response', () => {
        it('for an address no longer in the field raises no alert or notice, and shows the countdown when switching back', async () => {
            installAudio();
            const view = mountFaked('en');
            let release!: () => void;
            const gate = new Promise<void>((resolve) => {
                release = resolve;
            });
            outcomes.push({ ...lockedIn(420), gate });

            await fillAndSubmit(view, WHO_A);
            await settle(() => requests.length === 1);
            expect(requests).toHaveLength(1);

            // The user retypes the address while the request is in flight.
            await view.find('#login-email').setValue(WHO_B);
            release();
            await settle();

            expect(alertText(view)).toBe('');
            expect(view.text()).not.toContain(
                lockedFor('en', duration('en', 'durationMinutes', 7)),
            );
            expect(notice(view).exists()).toBe(false);
            expect(
                submitButton(view).attributes('aria-disabled'),
            ).toBeUndefined();
            expect(inertiaDefaultHandling).toEqual([]);
            expect(errorDialog()).toBeNull();

            await view.find('#login-email').setValue(WHO_A);
            await nextTick();

            expect(notice(view).exists()).toBe(true);
            expect(timer(view).text()).toBe('07:00');
        });
    });

    describe('leaving the page', () => {
        it('a later successful 2FA redirect leaves no interval and closes the AudioContext', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420));
            await submitFaked(view, WHO_A);

            clickSoundSync();
            await settle();
            expect(FakeAudioContext.instances).toHaveLength(1);
            const context = FakeAudioContext.instances[0];
            expect(context.close).not.toHaveBeenCalled();

            outcomes.push(
                inertiaPage(
                    'auth/TwoFactorChallenge',
                    {},
                    '/two-factor-challenge',
                ),
            );
            await submitFaked(view, WHO_B);
            await settle(() =>
                view.find('[data-test="two-factor-page"]').exists(),
            );

            expect(view.find('[data-test="two-factor-page"]').exists()).toBe(
                true,
            );
            expect(vi.getTimerCount()).toBe(0);
            expect(context.close).toHaveBeenCalled();
            expect(context.state).toBe('closed');
        });
    });

    describe('the optional bell', () => {
        it('is off by default: no context is created and nothing rings at expiry', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view);

            const toggle = soundToggle(view);
            expect(toggle.exists()).toBe(true);
            expect(toggle.attributes('aria-checked')).toBe('false');
            expect(FakeAudioContext.instances).toHaveLength(0);

            await advance(61_000);

            expect(FakeAudioContext.instances).toHaveLength(0);
            expect(occurrences(status(view).text(), readyText('en'))).toBe(1);
        });

        it('is a labelled checkbox with a hint, never posted, never persisted', async () => {
            installAudio();
            const storageBefore = storageSnapshot();
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view);

            const toggle = soundToggle(view);
            expect(toggle.attributes('name')).toBeUndefined();
            expect(toggle.attributes('aria-describedby')).toContain(
                'lockout-sound-hint',
            );
            expect(view.find('#lockout-sound-hint').text()).toBe(
                translator('en')('authUi.lockout.soundHint'),
            );
            const label = view.find('label[for="lockout-sound"]');
            expect(label.exists()).toBe(true);
            expect(label.text()).toContain(
                translator('en')('authUi.lockout.sound'),
            );

            clickSoundSync();
            await settle();
            expect(toggle.attributes('aria-checked')).toBe('true');

            // A later request carries the credentials only, nothing sound-ish.
            await advance(61_000);
            outcomes.push(wrongPassword());
            await submitFaked(view);
            expect(requests).toHaveLength(2);

            for (const request of requests) {
                const keys = postedKeys(request.data);
                expect(keys.join(' ')).not.toMatch(/sound|audio|bell|lockout/i);
                expect(keys).toEqual(
                    expect.arrayContaining(['email', 'password']),
                );
            }

            expect(storageSnapshot()).toBe(storageBefore);
        });

        it('resumes the context inside the click, then rings exactly once at expiry', async () => {
            installAudio('ok');
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view);

            clickSoundSync();

            // Synchronously, inside the user's click: created AND resumed.
            expect(FakeAudioContext.instances).toHaveLength(1);
            const context = FakeAudioContext.instances[0];
            expect(context.resume).toHaveBeenCalled();
            await settle();
            expect(blockedHint(view).exists()).toBe(false);

            await advance(61_000);
            await settle();

            expect(context.oscillators).toHaveLength(2);
            context.oscillators.forEach((osc) => {
                expect(osc.start).toHaveBeenCalled();
                expect(osc.stop).toHaveBeenCalled();
            });

            await advance(60_000);
            document.dispatchEvent(new Event('visibilitychange'));
            await settle();
            expect(context.oscillators).toHaveLength(2);
            expect(occurrences(status(view).text(), readyText('en'))).toBe(1);
        });

        it('keeps the focused sound toggle in the DOM at expiry, so its focus survives', async () => {
            installAudio('ok');
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view);

            const toggle = soundToggle(view).element as HTMLElement;
            toggle.focus();
            expect(document.activeElement).toBe(toggle);

            await advance(61_000);
            await settle();

            // The timer is gone, the control (and its focus) is not.
            expect(timer(view).exists()).toBe(false);
            expect(soundToggle(view).element).toBe(toggle);
            expect(document.activeElement).toBe(toggle);
        });

        it('unchecking the sound closes the context and expiry makes no noise', async () => {
            installAudio('ok');
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view);
            clickSoundSync();
            await settle();
            const context = FakeAudioContext.instances[0];

            clickSoundSync();
            await settle();
            expect(soundToggle(view).attributes('aria-checked')).toBe('false');
            expect(context.close).toHaveBeenCalled();

            await advance(61_000);
            await settle();

            expect(context.oscillators).toHaveLength(0);
            expect(occurrences(status(view).text(), readyText('en'))).toBe(1);
        });

        it.each(['reject', 'suspended'] as const)(
            'a %s context shows the blocked hint while the countdown and retry still work',
            async (mode) => {
                installAudio(mode);
                const view = mountFaked('en');
                outcomes.push(lockedIn(60));
                await submitFaked(view);

                clickSoundSync();
                await settle();

                const hint = blockedHint(view);
                expect(hint.exists()).toBe(true);
                expect(hint.text()).toBe(
                    translator('en')('authUi.lockout.soundBlocked'),
                );
                expect(hint.text()).toContain(
                    'Your browser blocked the sound.',
                );
                // Not a live region.
                expect(hint.attributes('role')).toBeUndefined();
                expect(hint.attributes('aria-live')).toBeUndefined();
                expect(hint.element.closest('[aria-live]')).toBeNull();
                expect(timer(view).text()).toBe('01:00');

                await advance(61_000);
                await settle();

                expect(occurrences(status(view).text(), readyText('en'))).toBe(
                    1,
                );
                expect(
                    submitButton(view).attributes('aria-disabled'),
                ).toBeUndefined();

                outcomes.push(lockedIn(120));
                await submitFaked(view);
                expect(requests).toHaveLength(2);
                expect(timer(view).text()).toBe('02:00');
            },
        );

        it('without any AudioContext the toggle is not rendered but the countdown still works', async () => {
            vi.stubGlobal('AudioContext', undefined);
            vi.stubGlobal('webkitAudioContext', undefined);
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));
            await submitFaked(view);

            expect(notice(view).exists()).toBe(true);
            expect(timer(view).text()).toBe('01:00');
            expect(soundToggle(view).exists()).toBe(false);
            expect(view.find('#lockout-sound-hint').exists()).toBe(false);

            await advance(61_000);

            expect(occurrences(status(view).text(), readyText('en'))).toBe(1);
        });
    });

    describe('accessibility and Arabic rendering', () => {
        it('the timer and its label are aria-hidden and the panel holds no live region', async () => {
            installAudio();
            const view = mountFaked('en');
            outcomes.push(lockedIn(420));
            await submitFaked(view);

            expect(timer(view).attributes('aria-hidden')).toBe('true');
            const label = translator('en')('authUi.lockout.remaining');
            const hiddenLabel = notice(view)
                .findAll('[aria-hidden="true"]')
                .some((el) => el.text() === label);
            expect(hiddenLabel).toBe(true);

            const panel = notice(view);
            expect(panel.find('[aria-live]').exists()).toBe(false);
            expect(panel.find('[role="status"]').exists()).toBe(false);
            expect(panel.find('[role="alert"]').exists()).toBe(false);
            expect(panel.element.closest('[aria-live]')).toBeNull();
        });

        it('the always-present status region is a polite, atomic status', () => {
            installAudio();
            const view = mountFaked('en');

            const region = status(view);
            expect(region.exists()).toBe(true);
            expect(region.attributes('role')).toBe('status');
            expect(region.attributes('aria-live')).toBe('polite');
            expect(region.attributes('aria-atomic')).toBe('true');
            expect(region.text()).toBe('');
        });

        it('Arabic: the timer is LTR, Western-digit, isolated; the panel itself is not forced LTR', async () => {
            installAudio();
            const view = mountFaked('ar');
            outcomes.push(lockedIn(420));
            await submitFaked(view);

            const el = timer(view);
            expect(el.attributes('dir')).toBe('ltr');
            expect(el.text()).toMatch(/^\d{1,2}:\d{2}(:\d{2})?$/);
            expect(el.text()).not.toMatch(/[٠-٩۰-۹]/);
            expect(el.text()).toBe('07:00');
            expect(el.attributes('aria-hidden')).toBe('true');
            expect(notice(view).attributes('dir')).not.toBe('ltr');
            expect(notice(view).element.closest('[dir="ltr"]')).toBeNull();
            expect(notice(view).text()).toContain(
                translator('ar')('authUi.lockout.remaining'),
            );

            await advance(5_000);
            expect(timer(view).text()).toBe('06:55');
            expect(timer(view).text()).not.toMatch(/[٠-٩۰-۹]/);
            expect(status(view).text()).not.toMatch(/[0-9٠-٩]/);
        });
    });

    describe('browser storage', () => {
        it('writes nothing to localStorage, sessionStorage or cookies through a whole lockout', async () => {
            installAudio();
            const before = storageSnapshot();
            const setItem = vi.spyOn(Storage.prototype, 'setItem');
            const view = mountFaked('en');
            outcomes.push(lockedIn(60));

            await submitFaked(view);
            clickSoundSync();
            await settle();
            await advance(61_000);
            await settle();

            expect(setItem).not.toHaveBeenCalled();
            expect(storageSnapshot()).toBe(before);
            setItem.mockRestore();
        });
    });
});
