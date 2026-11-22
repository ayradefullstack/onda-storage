// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    BELL_GRACE_MS,
    MAX_LOCKOUT_SECONDS,
    TICK_MS,
    canonicalLoginEmail,
    createDeadline,
    createLockoutRegistry,
    formatClock,
    lateByMs,
    remainingMs,
    remainingSeconds,
    systemClock,
} from './lockoutCountdown';
import type { LockoutClock } from './lockoutCountdown';

/**
 * A clock the test moves by hand. `mono` stands for performance.now() (never
 * jumps back, pauses while a laptop sleeps) and `wall` for Date.now() (the
 * user can change it, NTP can step it).
 */
function makeClock(): {
    clock: LockoutClock;
    state: { mono: number; wall: number };
} {
    const state = { mono: 10_000, wall: 1_790_000_000_000 };

    return {
        clock: { mono: () => state.mono, wall: () => state.wall },
        state,
    };
}

afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
});

describe('constants', () => {
    it('exposes the contract values', () => {
        expect(MAX_LOCKOUT_SECONDS).toBe(86_400);
        expect(TICK_MS).toBe(250);
        expect(BELL_GRACE_MS).toBe(90_000);
    });
});

describe('canonicalLoginEmail', () => {
    it.each([
        ['  Author@Example.COM ', 'author@example.com'],
        ['a@b.co', 'a@b.co'],
        ['\tMIXED@Case.org\n', 'mixed@case.org'],
        ['', ''],
        ['   ', ''],
    ])('%j -> %j', (raw, expected) => {
        expect(canonicalLoginEmail(raw)).toBe(expected);
    });
});

describe('createDeadline', () => {
    it('builds both deadlines from the clock and keeps the id and total', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(420, clock, 7);

        expect(deadline).not.toBeNull();
        expect(deadline?.id).toBe(7);
        expect(deadline?.totalSeconds).toBe(420);
        expect(deadline?.monoDeadline).toBe(state.mono + 420_000);
        expect(deadline?.wallDeadline).toBe(state.wall + 420_000);
    });

    it.each([0, -1, -0.5, Number.NaN, Number.POSITIVE_INFINITY])(
        'is null for %s',
        (seconds) => {
            expect(createDeadline(seconds, makeClock().clock, 1)).toBeNull();
        },
    );

    it('clamps an absurd duration to the maximum', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(10_000_000, clock, 1);

        expect(deadline?.totalSeconds).toBe(MAX_LOCKOUT_SECONDS);
        expect(deadline?.monoDeadline).toBe(
            state.mono + MAX_LOCKOUT_SECONDS * 1000,
        );
        expect(deadline?.wallDeadline).toBe(
            state.wall + MAX_LOCKOUT_SECONDS * 1000,
        );
    });

    it('keeps a duration exactly at the maximum', () => {
        expect(
            createDeadline(MAX_LOCKOUT_SECONDS, makeClock().clock, 1)
                ?.totalSeconds,
        ).toBe(MAX_LOCKOUT_SECONDS);
    });
});

describe('remainingMs / remainingSeconds', () => {
    it('is the full duration right after creation', () => {
        const { clock } = makeClock();
        const deadline = createDeadline(420, clock, 1)!;

        expect(remainingMs(deadline, clock)).toBe(420_000);
        expect(remainingSeconds(deadline, clock)).toBe(420);
    });

    it('follows ELAPSED time, not the number of ticks (one 600 s step)', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(1800, clock, 1)!;

        // A throttled background tab: no tick for ten minutes, then one read.
        state.mono += 600_000;
        state.wall += 600_000;

        expect(remainingSeconds(deadline, clock)).toBe(1200);
    });

    it('rounds partial seconds up and never goes below zero', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(10, clock, 1)!;

        state.mono += 9_001;
        state.wall += 9_001;
        expect(remainingMs(deadline, clock)).toBe(999);
        expect(remainingSeconds(deadline, clock)).toBe(1);

        state.mono += 999;
        state.wall += 999;
        expect(remainingSeconds(deadline, clock)).toBe(0);

        state.mono += 500_000;
        state.wall += 500_000;
        expect(remainingMs(deadline, clock)).toBe(0);
        expect(remainingSeconds(deadline, clock)).toBe(0);
    });

    it('does not extend the lockout when the wall clock jumps back', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(600, clock, 1)!;

        state.mono += 100_000;
        state.wall += 100_000;
        const before = remainingMs(deadline, clock);

        // The user rewinds the system clock by an hour.
        state.wall -= 3_600_000;

        expect(remainingMs(deadline, clock)).toBe(before);
        expect(remainingSeconds(deadline, clock)).toBe(500);
    });

    it('expires when the monotonic clock stalls but the wall clock advances', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(600, clock, 1)!;

        // mono does not move (suspended timers) while real time passes.
        state.wall += 601_000;

        expect(remainingMs(deadline, clock)).toBe(0);
        expect(remainingSeconds(deadline, clock)).toBe(0);
    });

    it('expires when the wall clock is set far forward', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(600, clock, 1)!;

        state.wall += 3_600_000;

        expect(remainingSeconds(deadline, clock)).toBe(0);
    });
});

describe('formatClock', () => {
    it.each([
        [0, '00:00'],
        [1, '00:01'],
        [59, '00:59'],
        [60, '01:00'],
        [420, '07:00'],
        [599, '09:59'],
        [1800, '30:00'],
        [3599, '59:59'],
        [3600, '1:00:00'],
        [5400, '1:30:00'],
        [3661, '1:01:01'],
        [86_400, '24:00:00'],
    ])('%i s -> %s', (seconds, expected) => {
        expect(formatClock(seconds)).toBe(expected);
    });

    it.each([-1, -3600, Number.NaN])('%s -> 00:00', (seconds) => {
        expect(formatClock(seconds)).toBe('00:00');
    });

    it('always uses Western digits', () => {
        for (const s of [0, 59, 3600, 5400, 86_400]) {
            expect(formatClock(s)).toMatch(/^[0-9:]+$/);
        }
    });
});

describe('createLockoutRegistry', () => {
    it('records, reads back, and canonicalises the email key', () => {
        const { clock } = makeClock();
        const registry = createLockoutRegistry(clock);

        const deadline = registry.record('  Author@Example.com ', 420);

        expect(deadline).not.toBeNull();
        expect(deadline?.totalSeconds).toBe(420);
        expect(registry.size).toBe(1);
        expect(registry.get('author@example.com')).toEqual(deadline);
        expect(registry.get('AUTHOR@EXAMPLE.COM')).toEqual(deadline);
    });

    it('returns null for an unusable duration', () => {
        const registry = createLockoutRegistry(makeClock().clock);

        expect(registry.record('a@b.co', 0)).toBeNull();
        expect(registry.record('a@b.co', Number.NaN)).toBeNull();
        expect(registry.get('a@b.co')).toBeFalsy();
    });

    it('a replacement for the same address gets a NEW id', () => {
        const { clock } = makeClock();
        const registry = createLockoutRegistry(clock);

        const first = registry.record('a@b.co', 60)!;
        const second = registry.record('A@B.co', 120)!;

        expect(second.id).not.toBe(first.id);
        expect(registry.size).toBe(1);
        expect(registry.get('a@b.co')?.id).toBe(second.id);
        expect(registry.get('a@b.co')?.totalSeconds).toBe(120);
    });

    it('keeps one entry per address', () => {
        const registry = createLockoutRegistry(makeClock().clock);

        registry.record('a@b.co', 60);
        registry.record('c@d.co', 90);

        expect(registry.size).toBe(2);
        expect(registry.get('a@b.co')?.totalSeconds).toBe(60);
        expect(registry.get('c@d.co')?.totalSeconds).toBe(90);
    });

    it('delete removes one entry (canonicalised) and clear removes all', () => {
        const registry = createLockoutRegistry(makeClock().clock);
        registry.record('a@b.co', 60);
        registry.record('c@d.co', 90);

        registry.delete(' A@B.co ');
        expect(registry.get('a@b.co')).toBeFalsy();
        expect(registry.get('c@d.co')).toBeTruthy();
        expect(registry.size).toBe(1);

        registry.clear();
        expect(registry.size).toBe(0);
        expect(registry.get('c@d.co')).toBeFalsy();
    });

    it('delete of an unknown address is harmless', () => {
        const registry = createLockoutRegistry(makeClock().clock);

        expect(() => registry.delete('nobody@b.co')).not.toThrow();
        expect(registry.size).toBe(0);
    });

    it('writes nothing to localStorage, sessionStorage or cookies', () => {
        const setItem = vi.spyOn(Storage.prototype, 'setItem');
        const cookies = document.cookie;
        const registry = createLockoutRegistry(makeClock().clock);

        registry.record('a@b.co', 60);
        registry.record('a@b.co', 90);
        registry.delete('a@b.co');
        registry.clear();

        expect(setItem).not.toHaveBeenCalled();
        expect(localStorage.length).toBe(0);
        expect(sessionStorage.length).toBe(0);
        expect(document.cookie).toBe(cookies);
    });
});

describe('systemClock', () => {
    it('reads performance.now and Date.now lazily on every call', () => {
        vi.useFakeTimers({
            toFake: ['Date', 'performance'],
        });
        vi.setSystemTime(new Date('2026-11-19T10:00:00Z'));

        const wallBefore = systemClock.wall();
        const monoBefore = systemClock.mono();
        vi.advanceTimersByTime(5_000);

        expect(systemClock.wall() - wallBefore).toBe(5_000);
        expect(systemClock.mono() - monoBefore).toBe(5_000);
    });
});

describe('lateByMs', () => {
    it('is how far the later of the two clocks is past its deadline (mono dominant)', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(60, clock, 1)!;

        state.mono += 60_000 + 7_000;
        state.wall += 60_000 + 2_000;

        expect(lateByMs(deadline, clock)).toBe(7_000);
    });

    it('wall dominant: a sleep moves the wall clock while the monotonic one pauses', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(60, clock, 1)!;

        state.mono += 10_000;
        state.wall += 60_000 + 200_000;

        expect(lateByMs(deadline, clock)).toBe(200_000);
    });

    it('wall moved backward: the monotonic term wins', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(60, clock, 1)!;

        state.mono += 60_000 + 3_000;
        state.wall -= 3_600_000;

        expect(lateByMs(deadline, clock)).toBe(3_000);
    });

    it('is negative while the deadline is still ahead on both clocks', () => {
        const { clock, state } = makeClock();
        const deadline = createDeadline(60, clock, 1)!;

        state.mono += 10_000;
        state.wall += 20_000;

        expect(lateByMs(deadline, clock)).toBe(-40_000);
    });
});
