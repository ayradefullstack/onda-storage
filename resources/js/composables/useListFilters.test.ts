// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';

const router = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({ router }));

const { LIST_FILTER_DEBOUNCE_MS, filterQuery, useListFilters } =
    await import('./useListFilters');

type Filters = { search: string; status: string; sort: string };

const DEFAULTS: Filters = { search: '', status: '', sort: 'newest' };

/** What a controller echoes as `filters`, held like a page prop. */
function serverProps(initial: Partial<Filters> = {}) {
    return reactive({ filters: { ...DEFAULTS, ...initial } });
}

function setup(initial: Partial<Filters> = {}) {
    const props = serverProps(initial);
    const list = useListFilters({
        url: '/author/oeuvres',
        filters: () => props.filters,
        defaults: DEFAULTS,
        only: ['oeuvres', 'counts'],
    });

    return { props, list };
}

/** The last router.get call as [url, query, options]. */
function lastVisit() {
    const call = router.get.mock.calls.at(-1);

    expect(call).toBeDefined();

    return call as [string, Record<string, string>, Record<string, unknown>];
}

beforeEach(() => {
    vi.useFakeTimers();
    router.get.mockReset();
    window.history.replaceState({}, '', '/author/oeuvres');
});

afterEach(() => vi.useRealTimers());

describe('filterQuery', () => {
    it('drops blanks and defaults, trims the rest', () => {
        expect(
            filterQuery(
                { search: '  abc ', status: '', sort: 'newest' },
                DEFAULTS,
            ),
        ).toEqual({ search: 'abc' });
        expect(
            filterQuery(
                { search: '   ', status: 'draft', sort: 'oldest' },
                DEFAULTS,
            ),
        ).toEqual({ status: 'draft', sort: 'oldest' });
    });
});

describe('useListFilters', () => {
    it('starts from props.filters and sends nothing on its own', async () => {
        const { list } = setup({ status: 'registered', search: 'opus' });

        await nextTick();
        vi.runAllTimers();

        expect(list.values).toEqual({
            search: 'opus',
            status: 'registered',
            sort: 'newest',
        });
        expect(list.isActive.value).toBe(true);
        expect(router.get).not.toHaveBeenCalled();
    });

    it('applies a select at once as a silent partial reload, without empty params', async () => {
        const { list } = setup();

        list.set({ status: 'draft' });
        await nextTick();

        expect(router.get).toHaveBeenCalledTimes(1);

        const [url, query, options] = lastVisit();

        expect(url).toBe('/author/oeuvres');
        expect(query).toEqual({ status: 'draft' });
        expect(options).toMatchObject({
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['oeuvres', 'counts', 'filters'],
            meta: { silent: true },
        });
    });

    it('applies a v-model change of a select at once too', async () => {
        const { list } = setup();

        list.values.sort = 'oldest';
        await nextTick();

        expect(lastVisit()[1]).toEqual({ sort: 'oldest' });
    });

    it('debounces typing (~300 ms) into a single visit with the last value', async () => {
        const { list } = setup();

        for (const value of ['o', 'op', 'opu', 'opus']) {
            list.values.search = value;
            await nextTick();
            vi.advanceTimersByTime(100);
        }

        expect(router.get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(LIST_FILTER_DEBOUNCE_MS);

        expect(router.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1]).toEqual({ search: 'opus' });
    });

    it('a select during a pending search sends both at once, then no late duplicate', async () => {
        const { list } = setup();

        list.values.search = 'opus';
        await nextTick();
        list.set({ status: 'submitted' });
        await nextTick();
        vi.advanceTimersByTime(LIST_FILTER_DEBOUNCE_MS * 2);

        expect(router.get).toHaveBeenCalledTimes(1);
        expect(lastVisit()[1]).toEqual({ search: 'opus', status: 'submitted' });
    });

    it('resets the page: `page` is never sent, `per_page` is kept', async () => {
        window.history.replaceState(
            {},
            '',
            '/author/oeuvres?page=4&per_page=50',
        );
        const { list } = setup();

        list.set({ status: 'registered' });
        await nextTick();

        expect(lastVisit()[1]).toEqual({
            per_page: '50',
            status: 'registered',
        });
        expect(lastVisit()[1]).not.toHaveProperty('page');
    });

    it('"Effacer" resets every control and sends no filter param', async () => {
        const { list } = setup({
            search: 'opus',
            status: 'draft',
            sort: 'oldest',
        });

        list.clear();
        await nextTick();

        expect(list.values).toEqual(DEFAULTS);
        expect(list.isActive.value).toBe(false);
        expect(lastVisit()[1]).toEqual({});
        expect(router.get).toHaveBeenCalledTimes(1);
    });

    it('re-syncs the controls when props.filters change (a response, back/forward)', async () => {
        const { props, list } = setup({ status: 'draft' });

        // Back/forward: Inertia restores an older page's props.
        props.filters = {
            search: 'opus',
            status: 'registered',
            sort: 'oldest',
        };
        await nextTick();
        await nextTick();

        expect(list.values).toEqual({
            search: 'opus',
            status: 'registered',
            sort: 'oldest',
        });
        // Following the server is not a filter change: no visit.
        vi.runAllTimers();
        expect(router.get).not.toHaveBeenCalled();
    });

    it('accepts null / boolean echoes (referentiel filters)', async () => {
        const props = reactive({
            filters: {
                search: '',
                college: null as string | null,
                needs_review: true,
            },
        });
        const list = useListFilters({
            url: '/admin/referentiel/documents',
            filters: () => props.filters,
            defaults: { search: '', college: '', needs_review: '' },
            only: ['rows'],
        });

        expect(list.values).toEqual({
            search: '',
            college: '',
            needs_review: '1',
        });

        list.set({ needs_review: '' });
        await nextTick();

        expect(lastVisit()[1]).toEqual({});
        expect(lastVisit()[2]).toMatchObject({ only: ['rows', 'filters'] });
    });

    it('does not overwrite what the user is still typing', async () => {
        const { props, list } = setup();

        list.values.search = 'sym';
        await nextTick();

        // An unrelated echo lands while the debounce is pending.
        props.filters = { ...DEFAULTS, status: '', search: 's' };
        await nextTick();

        expect(list.values.search).toBe('sym');

        vi.advanceTimersByTime(LIST_FILTER_DEBOUNCE_MS);
        expect(lastVisit()[1]).toEqual({ search: 'sym' });
    });

    it('clicking the chip that is already active sends nothing', async () => {
        const { list } = setup({ status: 'draft' });

        list.set({ status: 'draft' });
        await nextTick();

        expect(router.get).not.toHaveBeenCalled();
    });

    it('tracks the visit in `loading`', async () => {
        const { list } = setup();

        list.set({ status: 'draft' });
        const options = lastVisit()[2] as {
            onStart: () => void;
            onFinish: () => void;
        };

        options.onStart();
        expect(list.loading.value).toBe(true);
        options.onFinish();
        expect(list.loading.value).toBe(false);
    });
});
