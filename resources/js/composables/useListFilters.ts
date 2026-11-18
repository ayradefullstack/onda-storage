import { router } from '@inertiajs/vue3';
import {
    computed,
    getCurrentInstance,
    onBeforeUnmount,
    reactive,
    ref,
    watch,
} from 'vue';
import type { ComputedRef, Ref } from 'vue';

/**
 * The one way a server-filtered list page talks to its controller.
 *
 * The list itself is never held here: pages read their data prop directly
 * (`props.oeuvres.data`), so whatever the last visit returned is what is
 * rendered. This only owns the CONTROLS — the values in the search box and
 * the selects — and turns a change of those into a partial Inertia visit.
 *
 * - The controls start from `props.filters` and are re-synced whenever the
 *   server's echo of them changes (a response, browser back/forward), so the
 *   inputs can never disagree with the rows under them.
 * - Text keys (`search` by default) are debounced; everything else applies
 *   at once. A key at its default value is left out of the query, so
 *   "no filter" is a clean URL, and `page` is never sent: changing a filter
 *   always lands on page 1.
 * - The visit is a silent partial reload — `only` the list's data prop(s)
 *   plus `filters`, state and scroll kept, history replaced — so the global
 *   loader stays out of it; `loading` drives the list's own dimming instead.
 *
 * Kept across visits: `per_page` (a viewing preference, not a filter — the
 * pagination footer sets it). "Effacer" sends no filter param at all.
 */

export type FilterValues = Record<string, string>;

/** What a controller echoes back as `filters`: loosely typed on purpose. */
export type ServerFilters<F extends FilterValues> = {
    [K in keyof F]?: string | number | boolean | null;
};

export interface ListFiltersOptions<F extends FilterValues> {
    /** The list's index URL, without a query string. */
    url: string | (() => string);
    /** The server's echo of the active filters: `() => props.filters`. */
    filters: () => ServerFilters<F> | null | undefined;
    /** Every filter key with its "no filter" value (`''`, or e.g. `'newest'`). */
    defaults: F;
    /** The list's data prop(s); `filters` is always added. */
    only: string[];
    /** Typed-into keys, applied after `debounceMs`. Default `['search']`. */
    debounced?: Array<keyof F & string>;
    debounceMs?: number;
}

export interface ListFilters<F extends FilterValues> {
    /** The controls' values; bind inputs with `v-model="list.values.search"`. */
    values: F;
    /** Change one or more filters and apply at once (selects, chips). */
    set: (patch: Partial<F>) => void;
    /**
     * A value typed into a control: applied like `v-model` would, debounced
     * for text keys. For components that receive the list as a prop and
     * must not mutate it.
     */
    input: (key: keyof F & string, value: string) => void;
    /** Reset every control and reload with no filter param. */
    clear: () => void;
    /** Apply the current values now (cancels a pending debounce). */
    apply: () => void;
    /** At least one filter differs from its default. */
    isActive: ComputedRef<boolean>;
    /** A filter visit is in flight. */
    loading: Ref<boolean>;
}

export const LIST_FILTER_DEBOUNCE_MS = 300;

/** Query params that survive a filter change. */
const KEPT_PARAMS = ['per_page'];

function normalize<F extends FilterValues>(
    defaults: F,
    server: ServerFilters<F> | null | undefined,
): F {
    const values = { ...defaults };

    for (const key of Object.keys(defaults) as Array<keyof F>) {
        const raw = server?.[key];

        if (raw === null || raw === undefined || raw === false) {
            continue;
        }

        values[key] = (raw === true ? '1' : String(raw)) as F[keyof F];
    }

    return values;
}

/** The query a set of values produces: defaults and blanks left out. */
export function filterQuery<F extends FilterValues>(
    values: F,
    defaults: F,
): Record<string, string> {
    const query: Record<string, string> = {};

    for (const key of Object.keys(defaults)) {
        const value = (values[key] ?? '').trim();

        if (value !== '' && value !== defaults[key]) {
            query[key] = value;
        }
    }

    return query;
}

function keptParams(): Record<string, string> {
    if (typeof window === 'undefined') {
        return {};
    }

    const params = new URLSearchParams(window.location.search);
    const kept: Record<string, string> = {};

    for (const key of KEPT_PARAMS) {
        const value = params.get(key);

        if (value !== null && value !== '') {
            kept[key] = value;
        }
    }

    return kept;
}

export function useListFilters<F extends FilterValues>(
    options: ListFiltersOptions<F>,
): ListFilters<F> {
    const defaults = { ...options.defaults };
    const debounced = new Set<string>(options.debounced ?? ['search']);
    const debounceMs = options.debounceMs ?? LIST_FILTER_DEBOUNCE_MS;
    const only = [...new Set([...options.only, 'filters'])];

    const values = reactive(normalize(defaults, options.filters())) as F;
    const loading = ref(false);

    const keyOf = (v: F): string => JSON.stringify(filterQuery(v, defaults));

    // The query the list on screen answers to, or the one last requested:
    // a change of the controls back to it needs no visit.
    let settledKey = keyOf(values);
    let timer: ReturnType<typeof setTimeout> | undefined;

    const cancelTimer = (): void => {
        clearTimeout(timer);
        timer = undefined;
    };

    const resolveUrl = (): string =>
        typeof options.url === 'function' ? options.url() : options.url;

    const apply = (): void => {
        cancelTimer();

        const query = filterQuery(values, defaults);
        settledKey = JSON.stringify(query);

        router.get(resolveUrl(), { ...keptParams(), ...query }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only,
            // A filter is not a navigation: the global loader stays away
            // (see useGlobalLoader) and the list dims itself instead.
            meta: { silent: true },
            onStart: () => {
                loading.value = true;
            },
            onFinish: () => {
                loading.value = false;
            },
        } as Parameters<typeof router.get>[2]);
    };

    // The server's echo changed — a response landed, or back/forward
    // restored an older page. The controls follow it, unless the user is
    // mid-way through typing (their pending text wins; it is about to be
    // sent anyway).
    watch(
        () => keyOf(normalize(defaults, options.filters())),
        () => {
            if (timer !== undefined) {
                return;
            }

            const server = normalize(defaults, options.filters());
            settledKey = keyOf(server);
            Object.assign(values, server);
        },
    );

    watch(
        () => ({ ...values }),
        (now, before) => {
            if (keyOf(now as F) === settledKey) {
                cancelTimer();

                return;
            }

            const changed = Object.keys(now).filter(
                (key) => now[key] !== before[key],
            );

            if (
                changed.length > 0 &&
                changed.every((key) => debounced.has(key))
            ) {
                cancelTimer();
                timer = setTimeout(apply, debounceMs);

                return;
            }

            apply();
        },
    );

    const set = (patch: Partial<F>): void => {
        Object.assign(values, patch);

        // Clicking the chip that is already active: nothing to reload.
        if (keyOf(values) === settledKey && timer === undefined) {
            return;
        }

        apply();
    };

    const input = (key: keyof F & string, value: string): void => {
        (values as FilterValues)[key] = value;
    };

    const clear = (): void => {
        Object.assign(values, defaults);
        apply();
    };

    if (getCurrentInstance() !== null) {
        onBeforeUnmount(cancelTimer);
    }

    return {
        values,
        set,
        input,
        clear,
        apply,
        isActive: computed(
            () => Object.keys(filterQuery(values, defaults)).length > 0,
        ),
        loading,
    };
}
