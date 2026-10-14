// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    listeners: [] as (() => void)[],
}));

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: inertia.visit,
        on: (_event: string, listener: () => void) => {
            inertia.listeners.push(listener);

            return () => {
                inertia.listeners = inertia.listeners.filter(
                    (entry) => entry !== listener,
                );
            };
        },
    },
}));

vi.mock('@/routes/notifications', () => ({
    index: () => ({ url: '/notifications' }),
    read: (id: string) => ({ url: `/notifications/${id}/read` }),
    readAll: () => ({ url: '/notifications/read-all' }),
}));

const { default: NotificationPopover } =
    await import('./NotificationPopover.vue');

const REASON = 'Le contrat signé est illisible, merci de le redéposer.';
const label = {
    uuid: '0199-abcd',
    title: 'Symphonie des Aurès',
    college_name: 'Musique',
    created_at: '2026-10-01T08:00:00+00:00',
};

const rows = () => [
    {
        id: 'n1',
        data: {
            type: 'deposit',
            oeuvre_uuid: '0199-abcd',
            label_source: label,
            i18n_key: 'notifications.oeuvre.rejected',
            params: {},
            reason: REASON,
            url: 'http://onda-storage.test/author/oeuvres/0199-abcd',
        },
        read_at: null,
        created_at: new Date(Date.now() - 2 * 3600 * 1000).toISOString(),
    },
    {
        id: 'n2',
        data: {
            type: 'deposit',
            label_source: label,
            i18n_key: 'notifications.oeuvre.under_review',
            params: {},
            url: 'http://onda-storage.test/author/oeuvres/0199-abcd',
        },
        read_at: '2026-10-02T08:00:00+00:00',
        created_at: '2026-10-02T07:00:00+00:00',
    },
];

let wrapper: VueWrapper | null = null;
let fetchMock: ReturnType<typeof vi.fn>;

const settle = async (): Promise<void> => {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 30));
    await nextTick();
};

function stubFetch(unread = 1): void {
    fetchMock = vi.fn(async (url: string) => ({
        ok: true,
        status: 200,
        json: async () =>
            url.endsWith('/read-all') || url.endsWith('/read')
                ? {
                      unread_count: 0,
                      notifications: rows().map((row) => ({
                          ...row,
                          read_at: row.read_at ?? '2026-10-13T10:00:00+00:00',
                      })),
                  }
                : { unread_count: unread, notifications: rows() },
    }));

    vi.stubGlobal('fetch', fetchMock);
}

async function render(locale = 'fr'): Promise<void> {
    wrapper = mount(NotificationPopover, {
        global: {
            plugins: [
                createI18n({
                    legacy: false,
                    locale,
                    fallbackLocale: 'en',
                    messages: { en, fr, ar },
                    missingWarn: false,
                    fallbackWarn: false,
                }),
            ],
        },
        attachTo: document.body,
    });
    await settle();
}

async function openBell(): Promise<void> {
    const bell = document.querySelector<HTMLElement>(
        '[data-testid="notification-bell"]',
    )!;

    bell.focus();
    bell.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    );
    await settle();
}

const items = () => [
    ...document.querySelectorAll<HTMLElement>(
        '[data-testid="notification-item"]',
    ),
];

beforeEach(() => {
    inertia.visit.mockClear();
    inertia.listeners = [];
    document.cookie = 'XSRF-TOKEN=tok%3D';
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('NotificationPopover', () => {
    it('loads the real endpoint on mount and shows the unread count', async () => {
        stubFetch(3);
        await render();

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];

        expect(url).toBe('/notifications');
        expect(init.method).toBe('GET');
        expect(
            document.querySelector('[data-testid="notification-unread"]')!
                .textContent,
        ).toBe('3');
        expect(document.body.textContent).not.toContain('Virement');
    });

    it('shows no badge when nothing is unread', async () => {
        stubFetch(0);
        await render();

        expect(
            document.querySelector('[data-testid="notification-unread"]'),
        ).toBeNull();
    });

    it('refreshes after a navigation, with a fetch and never an Inertia visit', async () => {
        stubFetch();
        await render();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        inertia.listeners.forEach((listener) => listener());
        await settle();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(inertia.visit).not.toHaveBeenCalled();
    });

    it('lists the rows on open, rendered from key and params, with the reason', async () => {
        stubFetch();
        await render();
        await openBell();

        expect(fetchMock.mock.calls.length).toBeGreaterThanOrEqual(2);
        expect(items()).toHaveLength(2);

        const [rejected, underReview] = items();

        expect(rejected.dataset.read).toBe('false');
        expect(rejected.textContent).toContain('Symphonie des Aurès');
        expect(rejected.textContent).toContain(
            fr['notifications.oeuvre.rejected'],
        );
        expect(
            rejected.querySelector('[data-testid="notification-reason"]')!
                .textContent,
        ).toContain(REASON);
        expect(underReview.dataset.read).toBe('true');
        expect(underReview.textContent).toContain(
            fr['notifications.oeuvre.under_review'],
        );
    });

    it('renders the same stored row in the reader language', async () => {
        stubFetch();
        await render('ar');
        await openBell();

        expect(items()[0].textContent).toContain(
            ar['notifications.oeuvre.rejected'],
        );
    });

    it('clicking a row marks it read and goes to its oeuvre page', async () => {
        stubFetch();
        await render();
        await openBell();

        items()[0].click();
        await settle();

        const post = fetchMock.mock.calls.find(
            ([url]) => url === '/notifications/n1/read',
        ) as [string, RequestInit & { headers: Record<string, string> }];

        expect(post[1].method).toBe('POST');
        expect(post[1].headers['X-XSRF-TOKEN']).toBe('tok=');
        // Same-origin path only, whatever host the worker stored.
        expect(inertia.visit).toHaveBeenCalledWith('/author/oeuvres/0199-abcd');
        expect(
            document.querySelector('[data-testid="notification-unread"]'),
        ).toBeNull();
    });

    it('"mark all as read" posts and clears the count', async () => {
        stubFetch(2);
        await render();
        await openBell();

        document
            .querySelector<HTMLElement>(
                '[data-testid="notification-read-all"]',
            )!
            .click();
        await settle();

        expect(
            fetchMock.mock.calls.some(
                ([url, init]) =>
                    url === '/notifications/read-all' &&
                    (init as RequestInit).method === 'POST',
            ),
        ).toBe(true);
        expect(
            document.querySelector('[data-testid="notification-unread"]'),
        ).toBeNull();
        expect(items().every((item) => item.dataset.read === 'true')).toBe(
            true,
        );
    });

    it('says so when the list cannot be loaded', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: false,
                status: 500,
                json: async () => ({}),
            })),
        );
        fetchMock = fetch as unknown as ReturnType<typeof vi.fn>;
        await render();
        await openBell();

        expect(
            document.querySelector('[data-testid="notification-empty"]')!
                .textContent,
        ).toContain(fr['notifications.loadFailed']);
    });
});
