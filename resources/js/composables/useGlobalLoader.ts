import { router } from '@inertiajs/vue3';
import { readonly, ref } from 'vue';

/**
 * The global loading overlay, made "smart": it is a state machine driven by
 * Inertia's visit events, and it only ever shows for a real navigation that
 * is actually slow.
 *
 *   idle ──start──▶ pending ──300 ms──▶ visible ──3 s──▶ slow ──15 s──▶ verySlow
 *                      │                    │              │               │
 *                      └──finish──▶ idle    └──────────finish──────────────┘
 *                                                         │ (after ≥ 450 ms shown)
 *                                                         ▼
 *                                                      hiding ──200 ms──▶ idle
 *
 * Progress is one number for the ring and the bar: the visit's own when it
 * reports it (form uploads); otherwise a trickle that eases toward 90 % and
 * waits there, reaching 100 % only when the visit finishes.
 *
 * A visit that ends inside the 300 ms threshold shows nothing (Inertia's thin
 * top bar covers it). Once shown, the overlay stays at least 450 ms so it
 * cannot flicker. A cancelled or failed visit removes it at once.
 *
 * The machine is pure: no DOM, no router, and its clock is injectable, so
 * every rule below is unit-tested (useGlobalLoader.test.ts).
 */

export const SHOW_DELAY_MS = 300;
export const MIN_VISIBLE_MS = 450;
export const SLOW_AFTER_MS = 3_000;
export const VERY_SLOW_AFTER_MS = 15_000;
export const HIDE_MS = 200;

/** Without real progress the bar trickles toward, and never past, this. */
export const TRICKLE_MAX = 90;
export const TRICKLE_STEP_MS = 100;
const TRICKLE_EASE_MS = 1_500;

/**
 * The trickle: where the bar stands `elapsedMs` into a visit that reports no
 * progress of its own. It eases out toward 90 % and can never reach past it;
 * only the visit finishing takes the bar to 100 %.
 */
export function trickleProgress(elapsedMs: number): number {
    const eased = 1 - Math.exp(-Math.max(0, elapsedMs) / TRICKLE_EASE_MS);

    return Math.min(TRICKLE_MAX, Math.round(TRICKLE_MAX * eased));
}

export type LoaderPhase =
    'idle' | 'pending' | 'visible' | 'slow' | 'verySlow' | 'hiding';

/** The fields of an Inertia visit the loader reads. */
export interface LoaderVisit {
    id?: string;
    url?: URL | string;
    method?: string;
    data?: unknown;
    only?: readonly string[];
    except?: readonly string[];
    reset?: readonly string[];
    prefetch?: boolean;
    showProgress?: boolean;
    preserveState?: unknown;
    preserveScroll?: unknown;
    replace?: boolean;
    /** Set by Inertia on the visit it reports as finished. */
    cancelled?: boolean;
    interrupted?: boolean;
    meta?: { silent?: boolean };
}

export interface LoaderState {
    phase: LoaderPhase;
    /** 0..100, shown by both the ring arc and the bar. */
    progress: number;
    /** True once the visit reported real progress (a form upload). */
    real: boolean;
}

export interface LoaderClock {
    setTimeout: (callback: () => void, ms: number) => unknown;
    clearTimeout: (handle: unknown) => void;
    now: () => number;
}

const systemClock: LoaderClock = {
    setTimeout: (callback, ms) => setTimeout(callback, ms),
    clearTimeout: (handle) =>
        clearTimeout(handle as ReturnType<typeof setTimeout>),
    now: () => Date.now(),
};

/**
 * Mark a visit as a background refresh: it will never raise the overlay.
 * Used by the consultation poll, the deposit-status poll and every other
 * reload the user did not ask for.
 *
 *     router.reload(silent({ only: ['consultation'] }));
 */
export function silent<T extends object>(options: T = {} as T): T {
    return { ...options, meta: { silent: true } } as T;
}

/**
 * Whether a visit is a navigation the user is waiting on. Everything else
 * is excluded:
 *
 * - `meta.silent` — the explicit opt-out (see `silent()`);
 * - prefetch (hovering a link is not navigating);
 * - partial reloads (`only` / `except` / `reset`): the page stays, a prop
 *   is refreshed;
 * - `showProgress: false` — Inertia's own flag for async visits and polls;
 * - `preserveState` + `preserveScroll` together: filters, search, sort and
 *   pagination update a list in place.
 */
export function shouldTrackVisit(visit: LoaderVisit): boolean {
    if (visit.meta?.silent === true || visit.prefetch === true) {
        return false;
    }

    if (
        (visit.only?.length ?? 0) > 0 ||
        (visit.except?.length ?? 0) > 0 ||
        (visit.reset?.length ?? 0) > 0
    ) {
        return false;
    }

    if (visit.showProgress === false) {
        return false;
    }

    return !(visit.preserveState === true && visit.preserveScroll === true);
}

export interface LoaderMachine {
    readonly state: LoaderState;
    start: (visit: LoaderVisit) => void;
    progress: (percentage: number | null | undefined) => void;
    finish: (visit?: LoaderVisit) => void;
    /** A cancelled or failed visit: the overlay goes away immediately. */
    abort: () => void;
    /** The visit the overlay is currently about, for "Retry". */
    current: () => LoaderVisit | null;
    dispose: () => void;
}

export function createLoaderMachine(
    onChange: (state: LoaderState) => void = () => {},
    clock: LoaderClock = systemClock,
): LoaderMachine {
    const state: LoaderState = { phase: 'idle', progress: 0, real: false };
    let active: LoaderVisit | null = null;
    let startedAt = 0;
    let shownAt = 0;
    let timers: unknown[] = [];

    const emit = (): void => onChange({ ...state });

    const clearTimers = (): void => {
        timers.forEach((handle) => clock.clearTimeout(handle));
        timers = [];
    };

    const after = (ms: number, callback: () => void): void => {
        timers.push(clock.setTimeout(callback, Math.max(0, ms)));
    };

    const set = (phase: LoaderPhase): void => {
        state.phase = phase;
        emit();
    };

    const reset = (): void => {
        clearTimers();
        active = null;
        state.progress = 0;
        state.real = false;
        set('idle');
    };

    // Until the visit reports progress of its own, the bar trickles.
    const trickle = (): void => {
        if (!state.real) {
            const next = trickleProgress(clock.now() - startedAt);

            if (next !== state.progress) {
                state.progress = next;
                emit();
            }
        }

        after(TRICKLE_STEP_MS, trickle);
    };

    const show = (): void => {
        shownAt = clock.now();
        set('visible');

        const elapsed = shownAt - startedAt;

        after(SLOW_AFTER_MS - elapsed, () => {
            if (state.phase === 'visible') {
                set('slow');
            }
        });
        after(VERY_SLOW_AFTER_MS - elapsed, () => {
            if (state.phase === 'visible' || state.phase === 'slow') {
                set('verySlow');
            }
        });
    };

    const hide = (): void => {
        clearTimers();
        active = null;
        set('hiding');
        after(HIDE_MS, () => {
            state.progress = 0;
            state.real = false;
            set('idle');
        });
    };

    const shown = (): boolean =>
        state.phase === 'visible' ||
        state.phase === 'slow' ||
        state.phase === 'verySlow';

    return {
        state,

        start(visit) {
            if (!shouldTrackVisit(visit)) {
                return;
            }

            // A second navigation while one is on screen (a redirect, an
            // impatient click): the overlay carries on for the new visit.
            if (state.phase === 'pending' || shown()) {
                active = visit;

                return;
            }

            clearTimers();
            active = visit;
            startedAt = clock.now();
            state.progress = 0;
            state.real = false;
            set('pending');
            after(SHOW_DELAY_MS, show);
            after(TRICKLE_STEP_MS, trickle);
        },

        progress(percentage) {
            if (active === null || typeof percentage !== 'number') {
                return;
            }

            // Real progress replaces the trickle for the rest of the visit.
            const next = Math.max(0, Math.min(100, Math.round(percentage)));

            if (next !== state.progress || !state.real) {
                state.progress = next;
                state.real = true;
                emit();
            }
        },

        finish(visit) {
            // The end of a silent visit, or of one already replaced, is not
            // the end of the navigation the overlay is showing.
            if (
                active === null ||
                (visit !== undefined && !shouldTrackVisit(visit)) ||
                (visit?.id !== undefined &&
                    active.id !== undefined &&
                    visit.id !== active.id)
            ) {
                return;
            }

            // Cancelled or interrupted: gone at once, no minimum display.
            if (
                state.phase === 'pending' ||
                visit?.cancelled === true ||
                visit?.interrupted === true
            ) {
                reset();

                return;
            }

            if (!shown()) {
                return;
            }

            // Done: the bar completes, stays for the minimum, then fades.
            clearTimers();
            state.progress = 100;
            emit();
            after(MIN_VISIBLE_MS - (clock.now() - shownAt), hide);
        },

        abort() {
            if (state.phase !== 'idle') {
                reset();
            }
        },

        current: () => active,

        dispose: clearTimers,
    };
}

// ---------------------------------------------------------------------------
// The app's single loader: reactive state for AppLoader.vue, wired once to
// the Inertia router.
// ---------------------------------------------------------------------------

const phase = ref<LoaderPhase>('idle');
const progress = ref(0);
const real = ref(false);

const machine = createLoaderMachine((state) => {
    phase.value = state.phase;
    progress.value = state.progress;
    real.value = state.real;
});

/** Re-issue the visit the overlay is waiting on (the "Retry" button). */
function retry(): void {
    const visit = machine.current();

    machine.abort();

    if (visit?.url === undefined) {
        window.location.reload();

        return;
    }

    router.cancelAll();
    router.visit(visit.url.toString(), {
        method: (visit.method ?? 'get') as 'get',
        data: (visit.data ?? {}) as Record<string, never>,
        replace: visit.replace ?? false,
        preserveState: visit.preserveState === true,
        preserveScroll: visit.preserveScroll === true,
    });
}

export function useGlobalLoader() {
    return {
        phase: readonly(phase),
        progress: readonly(progress),
        real: readonly(real),
        retry,
    };
}

let isInitialized = false;

export function initializeGlobalLoader() {
    if (isInitialized || typeof window === 'undefined') {
        return;
    }

    isInitialized = true;

    router.on('start', (event) => machine.start(event.detail.visit));
    router.on('progress', (event) =>
        machine.progress(event.detail.progress?.percentage),
    );
    router.on('finish', (event) => machine.finish(event.detail.visit));

    // Failed: never leave the overlay up over a dead request. (A cancelled
    // visit reports itself through `finish`, with its `cancelled` flag; the
    // global `cancel` event carries no visit and also fires for background
    // requests, so it is not used.)
    router.on('httpException', () => machine.abort());
    router.on('networkError', () => machine.abort());
}
