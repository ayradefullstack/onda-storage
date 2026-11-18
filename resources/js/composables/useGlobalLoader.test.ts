import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    createLoaderMachine,
    HIDE_MS,
    MIN_VISIBLE_MS,
    shouldTrackVisit,
    SHOW_DELAY_MS,
    silent,
    SLOW_AFTER_MS,
    TRICKLE_MAX,
    trickleProgress,
    VERY_SLOW_AFTER_MS,
} from './useGlobalLoader';
import type { LoaderClock, LoaderVisit } from './useGlobalLoader';

vi.mock('@inertiajs/vue3', () => ({
    router: { on: vi.fn(), visit: vi.fn(), cancelAll: vi.fn() },
}));

/** A hand-rolled clock: the machine's timers are injectable for this. */
function fakeClock(): LoaderClock & { advance: (ms: number) => void } {
    let now = 0;
    let seq = 0;
    const pending = new Map<number, { at: number; callback: () => void }>();

    return {
        now: () => now,
        setTimeout: (callback, ms) => {
            pending.set(++seq, { at: now + ms, callback });

            return seq;
        },
        clearTimeout: (handle) => {
            pending.delete(handle as number);
        },
        advance(ms) {
            const end = now + ms;

            for (;;) {
                const due = [...pending.entries()]
                    .filter(([, timer]) => timer.at <= end)
                    .sort((a, b) => a[1].at - b[1].at)[0];

                if (due === undefined) {
                    break;
                }

                pending.delete(due[0]);
                now = due[1].at;
                due[1].callback();
            }

            now = end;
        },
    };
}

const visit = (over: Partial<LoaderVisit> = {}): LoaderVisit => ({
    id: 'v1',
    url: 'http://onda-storage.test/admin/oeuvres',
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

let clock: ReturnType<typeof fakeClock>;
let phases: string[];
let machine: ReturnType<typeof createLoaderMachine>;

beforeEach(() => {
    clock = fakeClock();
    phases = [];
    machine = createLoaderMachine((state) => {
        if (phases.at(-1) !== state.phase) {
            phases.push(state.phase);
        }
    }, clock);
});

describe('the delay threshold and minimum display', () => {
    it('never shows for a visit that ends within 300 ms', () => {
        machine.start(visit());
        clock.advance(SHOW_DELAY_MS - 1);
        machine.finish(visit());
        clock.advance(5_000);

        expect(phases).toEqual(['pending', 'idle']);
        expect(phases).not.toContain('visible');
    });

    it('shows once the visit is still running after 300 ms', () => {
        machine.start(visit());
        clock.advance(SHOW_DELAY_MS - 1);
        expect(machine.state.phase).toBe('pending');

        clock.advance(1);
        expect(machine.state.phase).toBe('visible');
    });

    it('stays at least 450 ms once shown, then hides', () => {
        machine.start(visit());
        clock.advance(SHOW_DELAY_MS + 50);
        machine.finish(visit()); // done 50 ms after appearing

        clock.advance(MIN_VISIBLE_MS - 50 - 1);
        expect(machine.state.phase).toBe('visible');

        clock.advance(1);
        expect(machine.state.phase).toBe('hiding');

        clock.advance(HIDE_MS);
        expect(machine.state.phase).toBe('idle');
    });

    it('hides at once when the visit ends after the minimum has passed', () => {
        machine.start(visit());
        clock.advance(SHOW_DELAY_MS + MIN_VISIBLE_MS + 200);
        machine.finish(visit());
        clock.advance(0);

        expect(machine.state.phase).toBe('hiding');
    });
});

describe('slow connections', () => {
    it('moves to "slow" after 3 s and "verySlow" after 15 s, measured from the start', () => {
        machine.start(visit());

        clock.advance(SLOW_AFTER_MS - 1);
        expect(machine.state.phase).toBe('visible');

        clock.advance(1);
        expect(machine.state.phase).toBe('slow');

        clock.advance(VERY_SLOW_AFTER_MS - SLOW_AFTER_MS - 1);
        expect(machine.state.phase).toBe('slow');

        clock.advance(1);
        expect(machine.state.phase).toBe('verySlow');
    });

    it('still closes normally from the very-slow state', () => {
        machine.start(visit());
        clock.advance(VERY_SLOW_AFTER_MS + 10);
        machine.finish(visit());
        clock.advance(HIDE_MS);

        expect(machine.state.phase).toBe('idle');
    });

    it('keeps the visit for a retry while it is waiting, and drops it after', () => {
        const slow = visit({ id: 'slow' });

        machine.start(slow);
        clock.advance(VERY_SLOW_AFTER_MS);
        expect(machine.current()).toBe(slow);

        machine.abort();
        expect(machine.current()).toBeNull();
    });
});

describe('visits that never raise the overlay', () => {
    const cases: [string, LoaderVisit][] = [
        ['a partial reload (only)', visit({ only: ['consultation'] })],
        ['a partial reload (except)', visit({ except: ['files'] })],
        ['a prefetch', visit({ prefetch: true, showProgress: false })],
        ['a silent visit', visit(silent({}))],
        [
            'a filter / sort / pagination visit',
            visit({ preserveState: true, preserveScroll: true }),
        ],
        ['an async visit or poll', visit({ showProgress: false })],
    ];

    it.each(cases)('%s', (_name, excluded) => {
        expect(shouldTrackVisit(excluded)).toBe(false);

        machine.start(excluded);
        clock.advance(VERY_SLOW_AFTER_MS + 1_000);

        expect(phases).toEqual([]);
        expect(machine.state.phase).toBe('idle');
    });

    it('tracks an ordinary navigation and a form submission', () => {
        expect(shouldTrackVisit(visit())).toBe(true);
        expect(shouldTrackVisit(visit({ method: 'post' }))).toBe(true);
        // preserveScroll alone (a delete button) is still a real request.
        expect(shouldTrackVisit(visit({ preserveScroll: true }))).toBe(true);
        expect(shouldTrackVisit(visit({ preserveState: true }))).toBe(true);
    });

    it('silent() keeps the options and adds the flag', () => {
        const options = silent({ only: ['consultation'] });

        expect(options.only).toEqual(['consultation']);
        expect((options as LoaderVisit).meta?.silent).toBe(true);
    });

    it('is not closed by a background visit finishing underneath it', () => {
        machine.start(visit({ id: 'page' }));
        clock.advance(SHOW_DELAY_MS);

        machine.finish(visit({ id: 'poll', only: ['consultation'] }));
        machine.finish(visit({ id: 'other' }));
        clock.advance(MIN_VISIBLE_MS + HIDE_MS);

        expect(machine.state.phase).toBe('visible');
    });
});

describe('cancelled and failed visits', () => {
    it('removes the overlay immediately when the visit is cancelled', () => {
        machine.start(visit());
        clock.advance(SHOW_DELAY_MS + 10);
        expect(machine.state.phase).toBe('visible');

        machine.finish(visit({ cancelled: true }));

        expect(machine.state.phase).toBe('idle'); // no minimum, no fade
    });

    it('removes it immediately when the visit is interrupted by another', () => {
        machine.start(visit());
        clock.advance(SHOW_DELAY_MS + 10);
        machine.finish(visit({ interrupted: true }));

        expect(machine.state.phase).toBe('idle');
    });

    it('removes it immediately on a failure (abort), also while pending', () => {
        machine.start(visit());
        clock.advance(100);
        machine.abort();
        clock.advance(10_000);

        expect(phases).toEqual(['pending', 'idle']);

        machine.start(visit());
        clock.advance(SLOW_AFTER_MS);
        machine.abort();

        expect(machine.state.phase).toBe('idle');
    });
});

describe('progress: the trickle and the real value', () => {
    it('eases toward 90 % and never passes it before the visit finishes', () => {
        const seen: number[] = [];

        machine = createLoaderMachine(
            (state) => seen.push(state.progress),
            clock,
        );
        machine.start(visit());

        clock.advance(SHOW_DELAY_MS);
        expect(machine.state.progress).toBeGreaterThan(0);
        expect(machine.state.progress).toBeLessThan(40);

        clock.advance(2_000);
        const early = machine.state.progress;

        clock.advance(120_000); // two minutes of waiting
        expect(machine.state.progress).toBe(TRICKLE_MAX);
        expect(machine.state.progress).toBeGreaterThan(early);
        expect(Math.max(...seen)).toBe(TRICKLE_MAX);
        // It only ever moves forward.
        expect(seen).toEqual([...seen].sort((x, y) => x - y));
        expect(machine.state.real).toBe(false);
    });

    it('trickleProgress is capped at 90 for any elapsed time', () => {
        expect(trickleProgress(0)).toBe(0);
        expect(trickleProgress(-50)).toBe(0);

        for (const ms of [100, 1_000, 5_000, 60_000, 3_600_000, Infinity]) {
            expect(trickleProgress(ms)).toBeLessThanOrEqual(TRICKLE_MAX);
        }

        expect(trickleProgress(3_600_000)).toBe(TRICKLE_MAX);
    });

    it('goes to 100 % when the visit finishes, then fades out', () => {
        machine.start(visit());
        clock.advance(SHOW_DELAY_MS + MIN_VISIBLE_MS + 500);
        expect(machine.state.progress).toBeLessThanOrEqual(TRICKLE_MAX);

        machine.finish(visit());
        expect(machine.state.progress).toBe(100);
        expect(machine.state.phase).toBe('visible');

        clock.advance(0);
        expect(machine.state.phase).toBe('hiding');
        expect(machine.state.progress).toBe(100); // full while it fades

        clock.advance(HIDE_MS);
        expect(machine.state).toMatchObject({ phase: 'idle', progress: 0 });
    });

    it('lets real progress override the trickle, for the rest of the visit', () => {
        machine.start(visit({ method: 'post' }));
        clock.advance(2_000);
        const trickled = machine.state.progress;

        expect(trickled).toBeGreaterThan(40);

        machine.progress(12.4); // the upload has only just begun
        expect(machine.state).toMatchObject({ progress: 12, real: true });

        clock.advance(10_000); // the trickle no longer moves it
        expect(machine.state.progress).toBe(12);

        machine.progress(97);
        expect(machine.state.progress).toBe(97); // real may pass 90

        machine.progress(250);
        expect(machine.state.progress).toBe(100);
    });

    it('starts the next visit from zero, as a trickle again', () => {
        machine.start(visit({ method: 'post' }));
        machine.progress(60);
        machine.finish(visit({ method: 'post' }));

        machine.start(visit({ id: 'next' }));

        expect(machine.state).toMatchObject({ progress: 0, real: false });
    });

    it('ignores progress when no visit is tracked', () => {
        machine.progress(50);

        expect(machine.state).toMatchObject({ progress: 0, real: false });
    });
});
