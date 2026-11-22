// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import type { EffectScope } from 'vue';
import type { BellArmResult, LockoutBell } from '@/lib/lockoutBell';
import { BELL_GRACE_MS } from '@/lib/lockoutCountdown';
import type { LockoutClock } from '@/lib/lockoutCountdown';
import { useLockoutCountdown } from './useLockoutCountdown';

const A = 'author@example.com';
const B = 'other@example.com';

const scopes: EffectScope[] = [];

function makeBell(
    over: Partial<{
        arm: () => Promise<BellArmResult>;
        ring: () => Promise<boolean>;
    }> = {},
) {
    return {
        supported: true,
        arm: vi.fn(over.arm ?? (async () => 'ready' as BellArmResult)),
        ring: vi.fn(over.ring ?? (async () => true)),
        disarm: vi.fn(async () => {}),
    };
}

function setup(
    options: { initial?: string; bell?: ReturnType<typeof makeBell> } = {},
) {
    const state = { mono: 10_000, wall: 1_790_000_000_000 };
    const clock: LockoutClock = {
        mono: () => state.mono,
        wall: () => state.wall,
    };
    const email = ref(options.initial ?? A);
    const onExpire = vi.fn();
    const bell = options.bell ?? makeBell();
    const scope = effectScope();
    scopes.push(scope);
    const api = scope.run(() =>
        useLockoutCountdown({
            email: () => email.value,
            clock,
            bell: bell as unknown as LockoutBell,
            onExpire,
        }),
    )!;

    return { state, clock, email, onExpire, bell, scope, api };
}

/** Move BOTH clocks without firing any timer (a throttled / frozen tab). */
function moveClock(state: { mono: number; wall: number }, ms: number): void {
    state.mono += ms;
    state.wall += ms;
}

/** Move the clocks, then let exactly one interval tick fire. */
function jump(state: { mono: number; wall: number }, ms: number): void {
    moveClock(state, ms);
    vi.advanceTimersByTime(250);
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
});

afterEach(() => {
    scopes.splice(0).forEach((scope) => scope.stop());
    vi.useRealTimers();
    vi.restoreAllMocks();
});

describe('starting a countdown', () => {
    it('shows a non-default 7-minute lockout for the displayed address', () => {
        const { api } = setup();

        const result = api.record(A, 420);

        expect(result).toEqual({ started: true, displayed: true });
        expect(api.active.value).toBe(true);
        expect(api.remainingSeconds.value).toBe(420);
        expect(api.clockText.value).toBe('07:00');
        expect(api.isLocked(A)).toBe(true);
        expect(api.ready.value).toBe(false);
    });

    it('canonicalises the address (case and whitespace)', () => {
        const { api } = setup({ initial: ' Author@Example.COM ' });

        const result = api.record('author@example.com', 420);

        expect(result.displayed).toBe(true);
        expect(api.isLocked('AUTHOR@example.com ')).toBe(true);
        expect(api.active.value).toBe(true);
    });

    it('records an address that is not displayed without showing it', () => {
        const { api } = setup();

        const result = api.record(B, 420);

        expect(result).toEqual({ started: true, displayed: false });
        expect(api.active.value).toBe(false);
        expect(api.isLocked(B)).toBe(true);
        expect(api.isLocked(A)).toBe(false);
    });

    it('clamps to 24 hours', () => {
        const { api } = setup();

        api.record(A, 9_999_999);

        expect(api.remainingSeconds.value).toBe(86_400);
        expect(api.clockText.value).toBe('24:00:00');
    });

    it.each([0, -5, Number.NaN, Number.POSITIVE_INFINITY])(
        'does not start for %s seconds',
        (seconds) => {
            const { api } = setup();

            const result = api.record(A, seconds);

            expect(result.started).toBe(false);
            expect(api.active.value).toBe(false);
            expect(api.isLocked(A)).toBe(false);
        },
    );

    it('record(x, null) deletes an existing entry and does not start', () => {
        const { api } = setup();
        api.record(A, 420);
        expect(api.isLocked(A)).toBe(true);

        const result = api.record(A, null);

        expect(result.started).toBe(false);
        expect(api.isLocked(A)).toBe(false);
    });

    it('runs exactly one interval while active, even after a second record', () => {
        const { api } = setup();
        expect(vi.getTimerCount()).toBe(0);

        api.record(A, 420);
        expect(vi.getTimerCount()).toBe(1);

        api.record(A, 600);
        expect(vi.getTimerCount()).toBe(1);
        expect(api.remainingSeconds.value).toBe(600);
    });
});

describe('ticking recomputes from the clock', () => {
    it('follows elapsed time tick by tick', () => {
        const { api, state } = setup();
        api.record(A, 420);

        jump(state, 1_000);
        expect(api.remainingSeconds.value).toBe(419);
        expect(api.clockText.value).toBe('06:59');

        jump(state, 59_000);
        expect(api.remainingSeconds.value).toBe(360);
        expect(api.clockText.value).toBe('06:00');
    });

    it('catches up after a delayed tick: the clock moves 10 min while only one tick fires', () => {
        const { api, state } = setup();
        api.record(A, 1_200);

        jump(state, 600_000);

        // A decrement-per-tick implementation would show 1199.
        expect(api.remainingSeconds.value).toBe(600);
        expect(api.clockText.value).toBe('10:00');
        expect(api.active.value).toBe(true);
    });

    it('does not extend the lockout when the wall clock jumps back', () => {
        const { api, state } = setup();
        api.record(A, 600);

        moveClock(state, 100_000);
        state.wall -= 3_600_000;
        vi.advanceTimersByTime(250);

        // 100 s of monotonic time elapsed (+ the tick's 250 ms does not move
        // the fake clock), the rewound wall clock must not add time back.
        expect(api.remainingSeconds.value).toBe(500);
    });

    it.each([
        [
            'document visibilitychange',
            () => document.dispatchEvent(new Event('visibilitychange')),
        ],
        ['window focus', () => window.dispatchEvent(new Event('focus'))],
        ['window pageshow', () => window.dispatchEvent(new Event('pageshow'))],
    ] as const)('%s forces a catch-up without any timer firing', (_n, fire) => {
        const { api, state } = setup();
        api.record(A, 1_200);

        // A frozen tab / bfcache page: time passes, no interval callback.
        moveClock(state, 300_000);
        expect(api.remainingSeconds.value).toBe(1_200);

        fire();

        expect(api.remainingSeconds.value).toBe(900);
        expect(api.clockText.value).toBe('15:00');
        expect(api.active.value).toBe(true);
    });

    it.each([
        [
            'document visibilitychange',
            () => document.dispatchEvent(new Event('visibilitychange')),
        ],
        ['window focus', () => window.dispatchEvent(new Event('focus'))],
        ['window pageshow', () => window.dispatchEvent(new Event('pageshow'))],
    ] as const)(
        '%s detects an expiry that happened while frozen',
        (_n, fire) => {
            const { api, state, onExpire } = setup();
            api.record(A, 120);

            moveClock(state, 121_000);
            fire();

            expect(api.active.value).toBe(false);
            expect(api.ready.value).toBe(true);
            expect(api.remainingSeconds.value).toBe(0);
            expect(onExpire).toHaveBeenCalledTimes(1);
        },
    );
});

describe('expiry', () => {
    it('fires exactly once: remainingSeconds 0, active false, ready true, entry deleted, onExpire(email)', () => {
        const { api, state, onExpire } = setup();
        api.record(A, 60);

        jump(state, 60_000);

        expect(api.remainingSeconds.value).toBe(0);
        expect(api.active.value).toBe(false);
        expect(api.ready.value).toBe(true);
        expect(api.isLocked(A)).toBe(false);
        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(onExpire).toHaveBeenCalledWith(A);
        expect(vi.getTimerCount()).toBe(0);
    });

    it('stays at one even after more ticks and every catch-up event', () => {
        const { api, state, onExpire } = setup();
        api.record(A, 60);
        jump(state, 61_000);

        vi.advanceTimersByTime(10_000);
        jump(state, 5_000);
        document.dispatchEvent(new Event('visibilitychange'));
        window.dispatchEvent(new Event('focus'));
        window.dispatchEvent(new Event('pageshow'));

        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(api.ready.value).toBe(true);
        expect(api.active.value).toBe(false);
    });

    it('clearReady() drops the ready flag', () => {
        const { api, state } = setup();
        api.record(A, 60);
        jump(state, 60_000);
        expect(api.ready.value).toBe(true);

        api.clearReady();

        expect(api.ready.value).toBe(false);
    });

    it('a fresh lockout after expiry is a new deadline: announced and rung again once', async () => {
        const { api, state, onExpire, bell } = setup();
        api.setSound(true);
        await vi.waitFor(() => expect(bell.arm).toHaveBeenCalled());

        api.record(A, 60);
        jump(state, 60_000);
        await nextTick();
        api.clearReady();

        api.record(A, 120);
        expect(api.active.value).toBe(true);
        expect(api.remainingSeconds.value).toBe(120);
        expect(api.ready.value).toBe(false);

        jump(state, 120_000);
        await nextTick();

        expect(onExpire).toHaveBeenCalledTimes(2);
        expect(bell.ring).toHaveBeenCalledTimes(2);
        expect(api.ready.value).toBe(true);
    });

    it('replacing a live lockout with a new deadline restarts the countdown', () => {
        const { api, state, onExpire } = setup();
        api.record(A, 60);
        jump(state, 30_000);

        api.record(A, 300);

        expect(api.remainingSeconds.value).toBe(300);
        jump(state, 60_000);
        expect(api.active.value).toBe(true);
        expect(onExpire).not.toHaveBeenCalled();
    });
});

describe('sound', () => {
    it('setSound(true) arms the bell and the bell rings exactly once at expiry', async () => {
        const { api, state, bell } = setup();

        api.setSound(true);
        expect(api.soundEnabled.value).toBe(true);
        expect(api.soundBlocked.value).toBe(false);
        expect(bell.arm).toHaveBeenCalledTimes(1);

        api.record(A, 60);
        jump(state, 60_000);
        await nextTick();
        vi.advanceTimersByTime(10_000);
        document.dispatchEvent(new Event('visibilitychange'));
        await nextTick();

        expect(bell.ring).toHaveBeenCalledTimes(1);
        expect(api.soundBlocked.value).toBe(false);
    });

    it('never rings when sound is off', async () => {
        const { api, state, bell, onExpire } = setup();

        api.record(A, 60);
        jump(state, 60_000);
        await nextTick();

        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(bell.ring).not.toHaveBeenCalled();
        expect(bell.arm).not.toHaveBeenCalled();
    });

    it('does not ring when the expiry is detected more than BELL_GRACE_MS late', async () => {
        const { api, state, bell, onExpire } = setup();
        api.setSound(true);

        api.record(A, 60);
        // The tab was frozen far past the deadline.
        jump(state, 60_000 + BELL_GRACE_MS + 1_000);
        await nextTick();

        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(api.ready.value).toBe(true);
        expect(bell.ring).not.toHaveBeenCalled();
    });

    it('still rings when the expiry is detected within the grace window', async () => {
        const { api, state, bell } = setup();
        api.setSound(true);

        api.record(A, 60);
        jump(state, 60_000 + BELL_GRACE_MS - 1_000);
        await nextTick();

        expect(bell.ring).toHaveBeenCalledTimes(1);
    });

    it('a ring that returns false flags soundBlocked', async () => {
        const bell = makeBell({ ring: async () => false });
        const { api, state } = setup({ bell });
        api.setSound(true);

        api.record(A, 60);
        jump(state, 60_000);
        await vi.waitFor(() => expect(api.soundBlocked.value).toBe(true));

        expect(api.ready.value).toBe(true);
    });

    it.each(['blocked', 'unsupported'] as const)(
        "an arm() result of '%s' flags soundBlocked",
        async (result) => {
            const bell = makeBell({ arm: async () => result });
            const { api } = setup({ bell });

            api.setSound(true);
            await vi.waitFor(() => expect(api.soundBlocked.value).toBe(true));

            expect(api.soundEnabled.value).toBe(true);
        },
    );

    it("an arm() result of 'ready' leaves soundBlocked false", async () => {
        const { api, bell } = setup();

        api.setSound(true);
        await vi.waitFor(() => expect(bell.arm).toHaveBeenCalled());
        await nextTick();

        expect(api.soundBlocked.value).toBe(false);
    });

    it('setSound(true) clears an earlier blocked flag', async () => {
        const arm = vi
            .fn<() => Promise<BellArmResult>>()
            .mockResolvedValueOnce('blocked')
            .mockResolvedValue('ready');
        const bell = makeBell({ arm });
        const { api } = setup({ bell });

        api.setSound(true);
        await vi.waitFor(() => expect(api.soundBlocked.value).toBe(true));

        api.setSound(true);
        expect(api.soundBlocked.value).toBe(false);
    });

    it('setSound(false) turns it off and disarms the bell', async () => {
        const { api, state, bell } = setup();
        api.setSound(true);

        api.setSound(false);
        expect(api.soundEnabled.value).toBe(false);
        expect(bell.disarm).toHaveBeenCalled();

        api.record(A, 60);
        jump(state, 60_000);
        await nextTick();

        expect(bell.ring).not.toHaveBeenCalled();
    });

    it('starts with the sound off and reports support from the bell', () => {
        const { api } = setup();

        expect(api.soundEnabled.value).toBe(false);
        expect(api.soundBlocked.value).toBe(false);
        expect(api.soundSupported).toBe(true);
    });

    it('does not persist the preference anywhere', () => {
        const setItem = vi.spyOn(Storage.prototype, 'setItem');
        const cookies = document.cookie;
        const { api } = setup();

        api.setSound(true);
        api.record(A, 60);
        api.setSound(false);

        expect(setItem).not.toHaveBeenCalled();
        expect(document.cookie).toBe(cookies);
    });
});

describe('switching the address', () => {
    it('hides the countdown for another address, and shows the ORIGINAL deadline when switching back', async () => {
        const { api, state, email } = setup();
        api.record(A, 420);

        email.value = B;
        await nextTick();
        expect(api.active.value).toBe(false);
        expect(api.ready.value).toBe(false);
        expect(api.isLocked(B)).toBe(false);
        expect(api.isLocked(A)).toBe(true);

        moveClock(state, 100_000);
        vi.advanceTimersByTime(250);

        email.value = A;
        await nextTick();

        expect(api.active.value).toBe(true);
        expect(api.remainingSeconds.value).toBe(320);
        expect(api.clockText.value).toBe('05:20');
        expect(vi.getTimerCount()).toBe(1);
    });

    it('switching to an address that is locked shows its own countdown', async () => {
        const { api, email } = setup();
        api.record(A, 420);
        api.record(B, 900);

        email.value = B;
        await nextTick();

        expect(api.active.value).toBe(true);
        expect(api.remainingSeconds.value).toBe(900);
    });

    it('an entry that expired while hidden is never announced, rung or shown', async () => {
        const { api, state, email, onExpire, bell } = setup();
        api.setSound(true);
        api.record(A, 120);

        email.value = B;
        await nextTick();
        moveClock(state, 500_000);
        vi.advanceTimersByTime(1_000);

        email.value = A;
        await nextTick();

        expect(api.active.value).toBe(false);
        expect(api.ready.value).toBe(false);
        expect(api.isLocked(A)).toBe(false);
        expect(onExpire).not.toHaveBeenCalled();
        expect(bell.ring).not.toHaveBeenCalled();
    });

    it('changing the address clears the ready flag', async () => {
        const { api, state, email } = setup();
        api.record(A, 60);
        jump(state, 60_000);
        expect(api.ready.value).toBe(true);

        email.value = B;
        await nextTick();

        expect(api.ready.value).toBe(false);
    });

    it('a lockout recorded for an address that is not displayed never announces or rings', () => {
        const { api, state, onExpire, bell } = setup({ initial: B });
        api.setSound(true);

        // A late response for A arrives while the field already holds B.
        api.record(A, 60);
        jump(state, 120_000);

        expect(onExpire).not.toHaveBeenCalled();
        expect(bell.ring).not.toHaveBeenCalled();
        expect(api.ready.value).toBe(false);
        expect(api.active.value).toBe(false);
    });
});

describe('dispose', () => {
    it('leaves no timer, no listener, disarms the bell and empties the registry', () => {
        const docAdd = vi.spyOn(document, 'addEventListener');
        const docRemove = vi.spyOn(document, 'removeEventListener');
        const winAdd = vi.spyOn(window, 'addEventListener');
        const winRemove = vi.spyOn(window, 'removeEventListener');

        const { api, bell, state } = setup();
        api.record(A, 420);
        api.record(A, 600);
        api.record(B, 100);
        jump(state, 1_000);

        const added = (spy: typeof docAdd, type: string) =>
            spy.mock.calls.filter((c) => c[0] === type);
        const removed = (spy: typeof docRemove, type: string) =>
            spy.mock.calls.filter((c) => c[0] === type);

        // Listeners are added ONCE, however many lockouts are recorded.
        expect(added(docAdd, 'visibilitychange')).toHaveLength(1);
        expect(added(winAdd, 'pageshow')).toHaveLength(1);
        expect(added(winAdd, 'focus')).toHaveLength(1);
        expect(vi.getTimerCount()).toBe(1);

        api.dispose();

        expect(vi.getTimerCount()).toBe(0);
        expect(bell.disarm).toHaveBeenCalled();
        expect(api.isLocked(A)).toBe(false);
        expect(api.isLocked(B)).toBe(false);

        // Every listener removed with the SAME handler it was added with.
        for (const [spyAdd, spyRem, type] of [
            [docAdd, docRemove, 'visibilitychange'],
            [winAdd, winRemove, 'pageshow'],
            [winAdd, winRemove, 'focus'],
        ] as const) {
            const add = added(spyAdd, type);
            const rem = removed(spyRem, type);
            expect(rem).toHaveLength(1);
            expect(rem[0][1]).toBe(add[0][1]);
        }
    });

    it('is also run when the owning effect scope stops', () => {
        const { api, bell, scope } = setup();
        api.record(A, 420);
        expect(vi.getTimerCount()).toBe(1);

        scope.stop();

        expect(vi.getTimerCount()).toBe(0);
        expect(bell.disarm).toHaveBeenCalled();
        expect(api.isLocked(A)).toBe(false);
    });

    it('stops reacting to events and clock changes afterwards', () => {
        const { api, state, onExpire } = setup();
        api.record(A, 60);
        api.dispose();

        moveClock(state, 120_000);
        document.dispatchEvent(new Event('visibilitychange'));
        window.dispatchEvent(new Event('focus'));
        window.dispatchEvent(new Event('pageshow'));
        vi.advanceTimersByTime(5_000);

        expect(onExpire).not.toHaveBeenCalled();
        expect(vi.getTimerCount()).toBe(0);
    });

    it('dispose() then scope.stop() is safe (idempotent)', () => {
        const { api, scope, bell } = setup();
        api.record(A, 420);

        api.dispose();

        expect(() => scope.stop()).not.toThrow();
        expect(vi.getTimerCount()).toBe(0);
        expect(bell.disarm.mock.calls.length).toBeGreaterThanOrEqual(1);
    });
});

describe('bell grace on two clocks (sleep / clock changes)', () => {
    const fireAll = () => {
        document.dispatchEvent(new Event('visibilitychange'));
        window.dispatchEvent(new Event('focus'));
        window.dispatchEvent(new Event('pageshow'));
    };

    it('after a sleep (only the wall clock advances past expiry + grace) expires without a bell and without replay', async () => {
        const { api, state, onExpire, bell } = setup();
        api.setSound(true);
        await vi.waitFor(() => expect(bell.arm).toHaveBeenCalled());
        api.record(A, 30);

        // The monotonic clock is paused (suspended machine).
        state.wall += 30_000 + BELL_GRACE_MS + 1;
        fireAll();
        fireAll();
        await nextTick();
        fireAll();
        vi.advanceTimersByTime(5_000);
        await nextTick();

        expect(api.ready.value).toBe(true);
        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(bell.ring).not.toHaveBeenCalled();
        expect(api.active.value).toBe(false);
        // Manual retry stays available.
        expect(api.isLocked(A)).toBe(false);
        expect(api.soundBlocked.value).toBe(false);
    });

    it('positive control: both clocks exactly BELL_GRACE_MS past the deadline ring once, even after repeated events', async () => {
        const { api, state, onExpire, bell } = setup();
        api.setSound(true);
        api.record(A, 30);

        moveClock(state, 30_000 + BELL_GRACE_MS);
        fireAll();
        await nextTick();
        fireAll();
        fireAll();
        vi.advanceTimersByTime(5_000);
        await nextTick();

        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(bell.ring).toHaveBeenCalledTimes(1);
        expect(api.ready.value).toBe(true);
        expect(api.isLocked(A)).toBe(false);
    });

    it('one millisecond beyond the grace on the wall clock silences the bell', async () => {
        const { api, state, bell } = setup();
        api.setSound(true);
        api.record(A, 30);

        state.mono += 30_000;
        state.wall += 30_000 + BELL_GRACE_MS + 1;
        fireAll();
        await nextTick();

        expect(bell.ring).not.toHaveBeenCalled();
    });

    it('never rings with the sound off, even when expiry is on time', async () => {
        const { api, state, bell, onExpire } = setup();
        api.record(A, 30);

        moveClock(state, 30_000);
        fireAll();
        await nextTick();

        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(bell.ring).not.toHaveBeenCalled();
    });

    it('wall clock moved backward: the monotonic clock decides and rings when within grace', async () => {
        const { api, state, bell, onExpire } = setup();
        api.setSound(true);
        api.record(A, 30);

        state.mono += 30_000 + 1_000;
        state.wall -= 3_600_000;
        fireAll();
        await nextTick();

        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(bell.ring).toHaveBeenCalledTimes(1);
    });

    it('a ring() that resolves false after dispose does not set soundBlocked', async () => {
        let resolveRing!: (v: boolean) => void;
        const ring = () =>
            new Promise<boolean>((resolve) => {
                resolveRing = resolve;
            });
        const bell = makeBell({ ring });
        const { api, state } = setup({ bell });
        api.setSound(true);
        api.record(A, 30);

        jump(state, 30_000);
        expect(bell.ring).toHaveBeenCalledTimes(1);

        api.dispose();
        resolveRing(false);
        await nextTick();
        await nextTick();

        expect(api.soundBlocked.value).toBe(false);
    });
});
