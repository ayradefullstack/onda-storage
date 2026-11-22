// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, effectScope, h } from 'vue';
import type { LockoutBell } from '@/lib/lockoutBell';
import { useLockoutCountdown } from './useLockoutCountdown';
import type { LockoutCountdown } from './useLockoutCountdown';

const TYPES = ['visibilitychange', 'pageshow', 'focus'];

const bell = {
    supported: true,
    arm: vi.fn(async () => 'ready' as const),
    ring: vi.fn(async () => true),
    disarm: vi.fn(async () => {}),
} as unknown as LockoutBell;

function spies() {
    const docAdd = vi.spyOn(document, 'addEventListener');
    const docRemove = vi.spyOn(document, 'removeEventListener');
    const winAdd = vi.spyOn(window, 'addEventListener');
    const winRemove = vi.spyOn(window, 'removeEventListener');
    const lockoutCalls = (spy: { mock: { calls: unknown[][] } }) =>
        spy.mock.calls.filter((c) => TYPES.includes(c[0] as string));

    return {
        adds: () => [...lockoutCalls(docAdd), ...lockoutCalls(winAdd)],
        removes: () => [...lockoutCalls(docRemove), ...lockoutCalls(winRemove)],
    };
}

function mountHost(
    onSetup?: (api: LockoutCountdown) => void,
    hostBell: LockoutBell = bell,
) {
    let api!: LockoutCountdown;
    const Host = defineComponent({
        setup() {
            api = useLockoutCountdown({
                email: () => 'a@b.c',
                bell: hostBell,
            });
            onSetup?.(api);

            return () => h('div');
        },
    });

    return { wrapper: mount(Host), api: () => api };
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('useLockoutCountdown listener lifecycle', () => {
    it('registers exactly visibilitychange, pageshow and focus once a component is mounted, and removes the same handlers on unmount', () => {
        const s = spies();
        const { wrapper } = mountHost();

        const adds = s.adds();
        expect(adds.map((c) => c[0]).sort()).toEqual([...TYPES].sort());
        // One shared tick handler for all three.
        expect(new Set(adds.map((c) => c[1])).size).toBe(1);
        expect(s.removes()).toHaveLength(0);

        wrapper.unmount();

        const removes = s.removes();
        expect(removes.map((c) => c[0]).sort()).toEqual([...TYPES].sort());
        expect(removes.map((c) => c[1])).toEqual(
            expect.arrayContaining([adds[0][1]]),
        );
        expect(removes.every((c) => c[1] === adds[0][1])).toBe(true);
    });

    it('registration happens once even if the API is used and disposed repeatedly', () => {
        const s = spies();
        const { wrapper, api } = mountHost();
        api().record('a@b.c', 60);
        api().record('a@b.c', 90);

        expect(s.adds()).toHaveLength(3);

        api().dispose();
        api().dispose();
        wrapper.unmount();

        expect(s.removes()).toHaveLength(3);
    });

    it('without a component instance it registers immediately, once, and scope.stop() removes them', () => {
        const s = spies();
        const scope = effectScope();

        scope.run(() => useLockoutCountdown({ email: () => 'a@b.c', bell }));

        expect(
            s
                .adds()
                .map((c) => c[0])
                .sort(),
        ).toEqual([...TYPES].sort());
        expect(s.removes()).toHaveLength(0);

        scope.stop();

        expect(
            s
                .removes()
                .map((c) => c[0])
                .sort(),
        ).toEqual([...TYPES].sort());
        scope.stop();
        expect(s.removes()).toHaveLength(3);
    });

    it('disposing before the component is mounted registers nothing and removes nothing', () => {
        const s = spies();

        const { wrapper } = mountHost((api) => api.dispose());

        expect(s.adds()).toHaveLength(0);
        expect(s.removes()).toHaveLength(0);

        wrapper.unmount();

        expect(s.adds()).toHaveLength(0);
        expect(s.removes()).toHaveLength(0);
    });

    // Control (flag set while mounted): useLockoutCountdown.test.ts, it.each blocked/unsupported.
    it('an arm() that settles non-ready after the component is unmounted leaves soundBlocked unchanged', async () => {
        let settle!: (result: 'blocked') => void;
        const arm = vi.fn(
            () =>
                new Promise<'blocked'>((resolve) => {
                    settle = resolve;
                }),
        );
        const deferredBell = {
            supported: true,
            arm,
            ring: vi.fn(async () => true),
            disarm: vi.fn(async () => {}),
        } as unknown as LockoutBell;

        const { wrapper, api } = mountHost(undefined, deferredBell);

        api().setSound(true);
        expect(arm).toHaveBeenCalledTimes(1);

        const before = api().soundBlocked.value;
        expect(before).toBe(false);

        wrapper.unmount();
        settle('blocked');
        await flushPromises();

        expect(api().soundBlocked.value).toBe(before);
    });

    it('a stopped no-instance scope never re-registers', () => {
        const s = spies();
        const scope = effectScope();
        const api = scope.run(() =>
            useLockoutCountdown({ email: () => 'a@b.c', bell }),
        )!;

        scope.stop();
        api.dispose();

        expect(s.adds()).toHaveLength(3);
        expect(s.removes()).toHaveLength(3);
    });
});
