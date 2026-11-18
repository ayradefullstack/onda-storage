// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';

/**
 * Regression for the review action that "did nothing useful".
 *
 * Root cause: the decisions lived in a reka-ui DropdownMenu whose trigger
 * was nested inside a TooltipTrigger (both `as-child`). The menu lost its
 * anchor and rendered off-screen at (0, -84) while its modal lock put
 * `pointer-events: none` on <body> — the page looked frozen — and even an
 * anchored menu dismissed the "Commencer l'examen" popover it opened, as
 * its closing focus return landed outside the popover.
 *
 * The row now has a plain "Examiner" button that opens a dialog directly:
 * no menu, no layer opened from another layer.
 */
const router = vi.hoisted(() => ({ get: vi.fn(), reload: vi.fn() }));
const toast = vi.hoisted(() => ({
    success: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    error: vi.fn(),
}));

vi.mock('vue-sonner', () => ({ toast }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: {
        props: ['href'],
        setup:
            (
                _props: unknown,
                { slots }: { slots: { default?: () => unknown } },
            ) =>
            () =>
                h('a', slots.default?.() as never),
    },
    router,
}));
vi.mock('@/routes/admin', () => ({ dashboard: () => ({ url: '/admin' }) }));
vi.mock('@/routes/admin/authors', () => ({
    show: (uuid: string) => ({ url: `/admin/authors/${uuid}` }),
}));
vi.mock('@/routes/admin/oeuvres', () => {
    const index = () => ({ url: '/admin/oeuvres' });
    index.url = () => '/admin/oeuvres';

    return {
        index,
        show: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}` }),
        decision: (uuid: string) => ({
            url: `/admin/oeuvres/${uuid}/decision`,
        }),
        review: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}/review` }),
        approve: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}/approve` }),
        reject: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}/reject` }),
    };
});

const { default: Index } = await import('./Index.vue');

const row = (uuid: string, status: string) => ({
    uuid,
    title: `Work ${uuid}`,
    college_name: null,
    status,
    author: { uuid: 'a1', name: 'Lina Haddad' },
    files_count: 1,
    files_size_bytes: 10,
    all_ready: true,
    has_blocking_file: false,
    created_at: '2026-10-01T10:00:00Z',
    submitted_at: '2026-10-02T10:00:00Z',
});

const summary = (uuid: string) => ({
    uuid,
    status: 'under_review',
    reference: uuid,
    title: `Work ${uuid}`,
    author: { name: 'Lina Haddad' },
    college: null,
    createdAt: '2026-10-01T10:00:00Z',
    submittedAt: '2026-10-02T10:00:00Z',
    holder: { id: 'me', name: 'Amina' },
    heldByMe: true,
    checklist: {
        noFiles: false,
        requiredMissing: [],
        filesNotReady: [],
        conditionalMissing: [],
    },
    allowed: ['approve', 'reject'],
    blocked: [],
    lastDecision: null,
});

let wrapper: VueWrapper | null = null;

function mountIndex(): VueWrapper {
    wrapper = mount(Index, {
        props: {
            oeuvres: {
                data: [
                    row('s1', 'submitted'),
                    row('r1', 'under_review'),
                    row('g1', 'registered'),
                ],
                links: [],
                from: 1,
                to: 3,
                total: 3,
                per_page: 25,
            },
            filters: { search: '', status: '', author: '', from: '', to: '' },
            statuses: ['submitted', 'under_review', 'registered'],
            counts: { to_review: 2 },
        },
        global: {
            plugins: [
                createI18n({
                    legacy: false,
                    locale: 'fr',
                    fallbackLocale: 'en',
                    messages: { en, fr, ar },
                    missingWarn: false,
                    fallbackWarn: false,
                }),
            ],
        },
        attachTo: document.body,
    });

    return wrapper;
}

function fakeServer() {
    const calls: string[] = [];
    const mock = vi.fn(async (url: string, init: RequestInit = {}) => {
        calls.push(`${init.method ?? 'GET'} ${url}`);
        const body = url.endsWith('/decision')
            ? summary(url.split('/')[3])
            : { status: 'registered', replayed: false };

        return { ok: true, status: 200, json: async () => body };
    });

    vi.stubGlobal('fetch', mock);

    return calls;
}

const settle = async (ms = 10): Promise<void> => {
    for (let i = 0; i < 4; i++) {
        await nextTick();
        await new Promise((resolve) => setTimeout(resolve, ms));
    }
};

const dialog = (): HTMLElement | null =>
    document.querySelector<HTMLElement>('[data-slot="app-dialog"]');

const examineButtons = (): HTMLButtonElement[] => [
    ...document.querySelectorAll<HTMLButtonElement>('[data-testid="examine"]'),
];

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    document.body.removeAttribute('style');
    vi.unstubAllGlobals();
    router.reload.mockClear();
});

describe('admin/oeuvres/Index — "Examiner"', () => {
    it('is a plain button on reviewable rows only, with no menu anywhere', async () => {
        fakeServer();
        mountIndex();
        await settle();

        expect(examineButtons()).toHaveLength(2);
        expect(
            document.querySelector('[data-testid="decision-menu"]'),
        ).toBeNull();
        expect(document.querySelector('[aria-haspopup="menu"]')).toBeNull();
        expect(examineButtons()[0].tagName).toBe('BUTTON');
        expect(examineButtons()[0].getAttribute('aria-haspopup')).toBe(
            'dialog',
        );
    });

    it("opens the dialog on click, fetches that row's summary, and the dialog stays open", async () => {
        const calls = fakeServer();
        mountIndex();
        await settle();

        examineButtons()[1].click();
        await settle();

        expect(calls).toEqual(['GET /admin/oeuvres/r1/decision']);
        expect(dialog()).not.toBeNull();
        expect(dialog()?.textContent).toContain('Work r1');

        // The old menu's layer closed the next one as focus moved back to
        // its trigger. Nothing may close this dialog on its own.
        await settle(60);
        expect(dialog()).not.toBeNull();
        expect(
            document.querySelector('[data-testid="decision-approve"]'),
        ).not.toBeNull();
    });

    it('leaves the page usable once the dialog closes', async () => {
        fakeServer();
        mountIndex();
        await settle();

        examineButtons()[0].click();
        await settle();
        expect(dialog()).not.toBeNull();

        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await settle(60);

        expect(dialog()).toBeNull();
        expect(document.body.style.pointerEvents).not.toBe('none');
    });

    it('a decision closes the dialog and reloads only the rows and the count, silently', async () => {
        const calls = fakeServer();
        mountIndex();
        await settle();

        examineButtons()[1].click();
        await settle();
        document
            .querySelector<HTMLButtonElement>(
                '[data-testid="decision-approve"]',
            )!
            .click();
        await settle(60);

        expect(calls).toEqual([
            'GET /admin/oeuvres/r1/decision',
            'POST /admin/oeuvres/r1/approve',
        ]);
        expect(dialog()).toBeNull();
        expect(router.reload).toHaveBeenCalledTimes(1);
        expect(router.reload).toHaveBeenCalledWith({
            only: ['oeuvres', 'counts'],
            meta: { silent: true },
        });
    });
});
