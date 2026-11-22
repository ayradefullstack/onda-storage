// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createLockoutBell } from './lockoutBell';

type ResumeMode = 'ok' | 'reject' | 'suspended';

type FakeOscillator = {
    type: string;
    frequency: { value: number; setValueAtTime: ReturnType<typeof vi.fn> };
    connect: ReturnType<typeof vi.fn>;
    start: ReturnType<typeof vi.fn>;
    stop: ReturnType<typeof vi.fn>;
};

type FakeGain = {
    gain: {
        value: number;
        setValueAtTime: ReturnType<typeof vi.fn>;
        exponentialRampToValueAtTime: ReturnType<typeof vi.fn>;
        linearRampToValueAtTime: ReturnType<typeof vi.fn>;
        cancelScheduledValues: ReturnType<typeof vi.fn>;
    };
    connect: ReturnType<typeof vi.fn>;
};

/**
 * Minimal AudioContext: starts 'suspended' like a browser before a user
 * gesture; resume() moves it to 'running' unless the mode says otherwise.
 */
function makeContext(mode: ResumeMode = 'ok') {
    const oscillators: FakeOscillator[] = [];
    const gains: FakeGain[] = [];
    const ctx = {
        mode,
        state: 'suspended' as string,
        currentTime: 2,
        destination: { kind: 'destination' },
        resume: vi.fn(async () => {
            if (ctx.mode === 'reject') {
                throw new Error('NotAllowedError');
            }

            if (ctx.mode === 'ok') {
                ctx.state = 'running';
            }
        }),
        close: vi.fn(async () => {
            ctx.state = 'closed';
        }),
        createOscillator: vi.fn(() => {
            const osc: FakeOscillator = {
                type: 'sine',
                frequency: {
                    value: 0,
                    setValueAtTime: vi.fn((v: number) => {
                        osc.frequency.value = v;
                    }),
                },
                connect: vi.fn((node: unknown) => node),
                start: vi.fn(),
                stop: vi.fn(),
            };
            oscillators.push(osc);

            return osc;
        }),
        createGain: vi.fn(() => {
            const gain: FakeGain = {
                gain: {
                    value: 1,
                    setValueAtTime: vi.fn(),
                    exponentialRampToValueAtTime: vi.fn(),
                    linearRampToValueAtTime: vi.fn(),
                    cancelScheduledValues: vi.fn(),
                },
                connect: vi.fn((node: unknown) => node),
            };
            gains.push(gain);

            return gain;
        }),
    };

    return { ctx, oscillators, gains };
}

const asFactory = (ctx: ReturnType<typeof makeContext>['ctx']) =>
    (() => ctx as unknown as AudioContext) as () => AudioContext | null;

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('arm', () => {
    it("is 'ready' when the context ends up running", async () => {
        const { ctx } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));

        await expect(bell.arm()).resolves.toBe('ready');
        expect(ctx.resume).toHaveBeenCalled();
        expect(ctx.state).toBe('running');
    });

    it("is 'blocked' when resume() rejects", async () => {
        const { ctx } = makeContext('reject');
        const bell = createLockoutBell(asFactory(ctx));

        await expect(bell.arm()).resolves.toBe('blocked');
    });

    it("is 'blocked' when the state stays suspended after resume()", async () => {
        const { ctx } = makeContext('suspended');
        const bell = createLockoutBell(asFactory(ctx));

        await expect(bell.arm()).resolves.toBe('blocked');
        expect(ctx.state).toBe('suspended');
    });

    it("is 'unsupported' when the factory returns null", async () => {
        const bell = createLockoutBell(() => null);

        await expect(bell.arm()).resolves.toBe('unsupported');
    });

    it("is 'unsupported' when the factory throws", async () => {
        const bell = createLockoutBell(() => {
            throw new Error('no audio here');
        });

        await expect(bell.arm()).resolves.toBe('unsupported');
    });

    it('creates the context synchronously, before any await (user gesture)', async () => {
        const { ctx } = makeContext('ok');
        const factory = vi.fn(asFactory(ctx));
        const bell = createLockoutBell(factory);

        const pending = bell.arm();

        // Nothing has been awaited yet: the context already exists and
        // resume() has already been requested inside the same task.
        expect(factory).toHaveBeenCalledTimes(1);
        expect(ctx.resume).toHaveBeenCalledTimes(1);

        await pending;
    });

    it('does not create a context at construction time (lazy)', () => {
        const factory = vi.fn(() => null);

        createLockoutBell(factory);

        expect(factory).not.toHaveBeenCalled();
    });
});

describe('ring', () => {
    it('is false before arm()', async () => {
        const { ctx, oscillators } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));

        await expect(bell.ring()).resolves.toBe(false);
        expect(oscillators).toHaveLength(0);
    });

    it('plays two sine partials (880 Hz and 1320 Hz) through one gain and returns true', async () => {
        const { ctx, oscillators, gains } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));
        await bell.arm();

        await expect(bell.ring()).resolves.toBe(true);

        expect(oscillators).toHaveLength(2);
        expect(oscillators.map((o) => o.type)).toEqual(['sine', 'sine']);
        expect(
            oscillators.map((o) => o.frequency.value).sort((x, y) => x - y),
        ).toEqual([880, 1320]);
        expect(gains).toHaveLength(1);
        oscillators.forEach((o) => expect(o.start).toHaveBeenCalled());
        // Every node is wired towards the destination.
        expect(gains[0].connect).toHaveBeenCalledWith(ctx.destination);
    });

    it('shapes a short envelope: 0.0001 -> 0.2 in 10 ms -> 0.0001 at 1.2 s', async () => {
        const { ctx, gains } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));
        await bell.arm();

        await bell.ring();

        const g = gains[0].gain;
        const t = ctx.currentTime;
        expect(g.setValueAtTime).toHaveBeenCalledWith(0.0001, t);
        const ramps = g.exponentialRampToValueAtTime.mock.calls as [
            number,
            number,
        ][];
        expect(ramps).toHaveLength(2);
        expect(ramps[0][0]).toBeCloseTo(0.2, 5);
        expect(ramps[0][1]).toBeCloseTo(t + 0.01, 5);
        expect(ramps[1][0]).toBeCloseTo(0.0001, 6);
        expect(ramps[1][1]).toBeCloseTo(t + 1.2, 5);
    });

    it('stops every oscillator within 1.5 s', async () => {
        const { ctx, oscillators } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));
        await bell.arm();

        await bell.ring();

        for (const osc of oscillators) {
            expect(osc.stop).toHaveBeenCalledTimes(1);
            const at = osc.stop.mock.calls[0][0] as number;
            expect(at).toBeGreaterThan(ctx.currentTime);
            expect(at).toBeLessThanOrEqual(ctx.currentTime + 1.5);
        }
    });

    it('is false when resume() rejects at ring time', async () => {
        const { ctx, oscillators } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));
        await bell.arm();

        ctx.mode = 'reject';
        ctx.state = 'suspended';

        await expect(bell.ring()).resolves.toBe(false);
        expect(oscillators).toHaveLength(0);
    });

    it("is false when the state is not 'running' after resume()", async () => {
        const { ctx, oscillators } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));
        await bell.arm();

        ctx.mode = 'suspended';
        ctx.state = 'interrupted';

        await expect(bell.ring()).resolves.toBe(false);
        expect(oscillators).toHaveLength(0);
    });

    it('never throws when the audio graph cannot be built', async () => {
        const { ctx } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));
        await bell.arm();
        ctx.createOscillator.mockImplementation(() => {
            throw new Error('graph failure');
        });

        const result = await bell.ring();

        expect(typeof result).toBe('boolean');
    });

    it('is false after disarm()', async () => {
        const { ctx } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));
        await bell.arm();
        await bell.disarm();

        await expect(bell.ring()).resolves.toBe(false);
    });
});

describe('disarm', () => {
    it('closes the context exactly once, even when called twice', async () => {
        const { ctx } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));
        await bell.arm();

        await bell.disarm();
        await bell.disarm();

        expect(ctx.close).toHaveBeenCalledTimes(1);
    });

    it('does nothing (and does not throw) when never armed', async () => {
        const { ctx } = makeContext('ok');
        const bell = createLockoutBell(asFactory(ctx));

        await expect(bell.disarm()).resolves.toBeUndefined();
        expect(ctx.close).not.toHaveBeenCalled();
    });

    it('swallows a rejected close()', async () => {
        const { ctx } = makeContext('ok');
        ctx.close.mockRejectedValue(new Error('already closed'));
        const bell = createLockoutBell(asFactory(ctx));
        await bell.arm();

        await expect(bell.disarm()).resolves.toBeUndefined();
    });

    it('swallows a synchronous throw from close()', async () => {
        const { ctx } = makeContext('ok');
        ctx.close.mockImplementation(() => {
            throw new Error('boom');
        });
        const bell = createLockoutBell(asFactory(ctx));
        await bell.arm();

        await expect(bell.disarm()).resolves.toBeUndefined();
    });
});

describe('default factory (window.AudioContext / webkitAudioContext)', () => {
    it('uses window.AudioContext, resolved lazily at arm() time', async () => {
        const { ctx } = makeContext('ok');
        const bell = createLockoutBell();

        // Installed AFTER the bell exists: the lookup must be lazy.
        vi.stubGlobal(
            'AudioContext',
            class {
                constructor() {
                    return ctx;
                }
            },
        );

        expect(bell.supported).toBe(true);
        await expect(bell.arm()).resolves.toBe('ready');
    });

    it('falls back to webkitAudioContext', async () => {
        const { ctx } = makeContext('ok');
        vi.stubGlobal('AudioContext', undefined);
        vi.stubGlobal(
            'webkitAudioContext',
            class {
                constructor() {
                    return ctx;
                }
            },
        );
        const bell = createLockoutBell();

        expect(bell.supported).toBe(true);
        await expect(bell.arm()).resolves.toBe('ready');
    });

    it("reports 'unsupported' when neither constructor exists", async () => {
        vi.stubGlobal('AudioContext', undefined);
        vi.stubGlobal('webkitAudioContext', undefined);
        const bell = createLockoutBell();

        expect(bell.supported).toBe(false);
        await expect(bell.arm()).resolves.toBe('unsupported');
        await expect(bell.ring()).resolves.toBe(false);
    });
});
