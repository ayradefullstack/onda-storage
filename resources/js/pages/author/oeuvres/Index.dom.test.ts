// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';

/**
 * Regression for "filters update the URL but the list only shows after F5".
 *
 * Root cause: DataTableEmpty declared `withDefaults({ icon: SearchX })`.
 * Vue treats a function default as a factory, and a lucide icon IS a
 * function, so the first filter with no result called `SearchX(props)` with
 * no component instance and threw "Cannot destructure property 'slots' of
 * 'undefined'" in the middle of the patch. The page's vnode tree was left
 * pointing at removed DOM: the empty state (and its "Effacer") never
 * appeared, and every later visit threw "Cannot set properties of null
 * (setting '__vnode')" — the server answered correctly, the client could
 * no longer render it.
 *
 * The real DataTableEmpty and the real icons are mounted here on purpose;
 * any render error fails the test through the app's errorHandler.
 */
const router = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn() }));

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
vi.mock('@/routes/oeuvres', () => {
    const index = () => ({ url: '/author/oeuvres', method: 'get' });
    index.url = () => '/author/oeuvres';

    return {
        index,
        create: () => ({ url: '/author/oeuvres/create', method: 'get' }),
        show: (uuid: string) => ({ url: `/author/oeuvres/${uuid}` }),
        edit: (uuid: string) => ({ url: `/author/oeuvres/${uuid}/edit` }),
        destroy: (uuid: string) => ({ url: `/author/oeuvres/${uuid}` }),
    };
});
vi.mock('@/routes/author/oeuvres', () => ({
    submit: (uuid: string) => ({ url: `/author/oeuvres/${uuid}/submit` }),
}));

const { default: Index } = await import('./Index.vue');

type Filters = { search: string; status: string; sort: string };

const NO_FILTERS: Filters = { search: '', status: '', sort: 'newest' };
const COUNTS = {
    all: 3,
    draft: 0,
    submitted: 1,
    under_review: 0,
    registered: 2,
    rejected: 0,
};

const oeuvre = (id: number, status: string) => ({
    id,
    uuid: `0000000${id}-0000-7000-8000-00000000000${id}`,
    title: `Œuvre ${id} ·`,
    description: null,
    status,
    created_at: '2026-10-01T10:00:00Z',
    submitted_at: null,
    media_files_count: 1,
    college_name: null,
    documents: { satisfied: 1, total: 1, conditional: 0 },
    can: { edit: false, delete: false, submit: false },
});

const page = (rows: ReturnType<typeof oeuvre>[]) => ({
    data: rows,
    links: [],
    current_page: 1,
    last_page: 1,
    from: rows.length > 0 ? 1 : null,
    to: rows.length > 0 ? rows.length : null,
    total: rows.length,
});

const ALL = page([
    oeuvre(20, 'submitted'),
    oeuvre(6, 'registered'),
    oeuvre(5, 'registered'),
]);
const REGISTERED = page([oeuvre(6, 'registered'), oeuvre(5, 'registered')]);
const NONE = page([]);

let wrapper: VueWrapper | null = null;
let renderErrors: unknown[] = [];

function mountIndex(): VueWrapper {
    wrapper = mount(Index, {
        props: {
            oeuvres: ALL,
            filters: NO_FILTERS,
            statuses: [
                'draft',
                'submitted',
                'under_review',
                'registered',
                'rejected',
            ],
            sorts: ['newest', 'oldest', 'title'],
            counts: COUNTS,
        },
        attachTo: document.body,
        global: {
            plugins: [
                createI18n({
                    legacy: false,
                    locale: 'fr',
                    fallbackLocale: 'fr',
                    missingWarn: false,
                    fallbackWarn: false,
                    messages: { fr, en, ar },
                }),
            ],
            config: {
                errorHandler: (error) => {
                    renderErrors.push(error);
                },
            },
        },
    });

    return wrapper;
}

/** Simulates the partial reload landing: new `oeuvres` + `filters` props. */
async function respond(
    oeuvres: ReturnType<typeof page>,
    filters: Partial<Filters>,
) {
    await wrapper!.setProps({
        oeuvres,
        filters: { ...NO_FILTERS, ...filters },
    });
    await nextTick();
}

const rowTitles = (w: VueWrapper) =>
    w.findAll('[data-test="oeuvres-list"] tbody tr').map((tr) => {
        const match = tr.text().match(/Œuvre \d+/);

        return match ? match[0] : tr.text();
    });

const tab = (w: VueWrapper, label: RegExp) => {
    const button = w
        .findAll('button')
        .find((b) => label.test(b.text()) && /\(\d+\)/.test(b.text()));

    expect(button, `tab ${label}`).toBeDefined();

    return button!;
};

beforeEach(() => {
    vi.useFakeTimers();
    router.get.mockReset();
    renderErrors = [];
    window.history.replaceState({}, '', '/author/oeuvres');
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('author oeuvres list — filters render without a refresh', () => {
    it('REGRESSION: an empty filter result renders its empty state, and the list comes back after', async () => {
        const w = mountIndex();

        expect(rowTitles(w)).toEqual(['Œuvre 20', 'Œuvre 6', 'Œuvre 5']);

        // "Brouillons (0)": the server answers with zero rows.
        await tab(w, /Brouillons/).trigger('click');
        await respond(NONE, { status: 'draft' });

        expect(renderErrors).toEqual([]);
        const empty = w.find('[data-test="list-empty"]');
        expect(empty.exists()).toBe(true);
        expect(empty.text()).toContain(
            'Aucune œuvre ne correspond à ces filtres',
        );
        expect(w.find('[data-test="list-empty-clear"]').text()).toBe(
            'Effacer les filtres',
        );

        // "Effacer les filtres" in the empty state: an empty query...
        router.get.mockReset();
        await w.find('[data-test="list-empty-clear"]').trigger('click');
        expect(router.get).toHaveBeenCalledTimes(1);
        expect(router.get.mock.calls[0][1]).toEqual({});

        // ...and when it lands, the full list is back, with no render error.
        await respond(ALL, {});
        expect(renderErrors).toEqual([]);
        expect(rowTitles(w)).toEqual(['Œuvre 20', 'Œuvre 6', 'Œuvre 5']);

        // The page keeps rendering every later response too.
        await respond(REGISTERED, { status: 'registered' });
        expect(rowTitles(w)).toEqual(['Œuvre 6', 'Œuvre 5']);
        expect(renderErrors).toEqual([]);
    });

    it('new props after a visit re-render the rows (props A → props B)', async () => {
        const w = mountIndex();

        await respond(REGISTERED, { status: 'registered' });

        expect(rowTitles(w)).toEqual(['Œuvre 6', 'Œuvre 5']);
        // Keyed by the record: each row carries its own uuid's actions.
        expect(w.findAll('[data-test="oeuvres-list"] tbody tr')).toHaveLength(
            2,
        );
    });

    it('a status tab sends the right partial reload, without empty params', async () => {
        const w = mountIndex();

        await tab(w, /Enregistrées/).trigger('click');
        await nextTick();

        expect(router.get).toHaveBeenCalledTimes(1);
        const [url, query, options] = router.get.mock.calls[0];
        expect(url).toBe('/author/oeuvres');
        expect(query).toEqual({ status: 'registered' });
        expect(options).toMatchObject({
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['oeuvres', 'counts', 'filters'],
            meta: { silent: true },
        });
    });

    it('the controls follow props.filters (response, back/forward)', async () => {
        const w = mountIndex();

        await respond(REGISTERED, { status: 'registered', search: 'opus' });
        await nextTick();

        const active = tab(w, /Enregistrées/);
        expect(active.classes()).toContain('font-semibold');
        expect(
            (w.find('input[type="text"]').element as HTMLInputElement).value,
        ).toBe('opus');
        expect(router.get).not.toHaveBeenCalled();
    });

    it('rows a filter brings in are visible: no reveal class, no opacity 0', async () => {
        const w = mountIndex();

        await respond(REGISTERED, { status: 'registered' });
        await respond(ALL, {});

        for (const tr of w.findAll('[data-test="oeuvres-list"] tbody tr')) {
            expect(tr.classes()).not.toContain('motion-reveal');
            expect((tr.element as HTMLElement).style.opacity).not.toBe('0');
        }
    });

    it('dims the list while the filter visit runs', async () => {
        const w = mountIndex();

        await tab(w, /Enregistrées/).trigger('click');
        const options = router.get.mock.calls[0][2] as {
            onStart: () => void;
            onFinish: () => void;
        };

        options.onStart();
        await nextTick();
        expect(
            w.find('[data-test="oeuvres-list"]').attributes('aria-busy'),
        ).toBe('true');

        options.onFinish();
        await nextTick();
        expect(
            w.find('[data-test="oeuvres-list"]').attributes('aria-busy'),
        ).toBeUndefined();
    });
});
