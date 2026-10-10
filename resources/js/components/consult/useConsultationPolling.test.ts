import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import {
    POLL_INTERVAL_MS,
    POLL_MAX_MS,
    useConsultationPolling,
} from './useConsultationPolling';

function mount(initialStatus: string, refresh: () => void) {
    const status = ref<string | undefined>(initialStatus);
    const key = ref('file-a');
    const polling = effectScope().run(() =>
        useConsultationPolling(status, key, refresh),
    );

    return { status, key, gaveUp: () => polling?.gaveUp.value ?? false };
}

describe('useConsultationPolling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('refreshes every 5 seconds while pending', async () => {
        const refresh = vi.fn();
        mount('pending', refresh);

        vi.advanceTimersByTime(POLL_INTERVAL_MS * 3);

        expect(refresh).toHaveBeenCalledTimes(3);
    });

    it('stops after 2 minutes and reports it gave up', async () => {
        const refresh = vi.fn();
        const view = mount('pending', refresh);

        vi.advanceTimersByTime(POLL_MAX_MS + POLL_INTERVAL_MS * 4);

        const calls = refresh.mock.calls.length;
        expect(view.gaveUp()).toBe(true);

        vi.advanceTimersByTime(POLL_INTERVAL_MS * 10);
        expect(refresh).toHaveBeenCalledTimes(calls);
        // 2 minutes at one call per 5 s is at most 24 refreshes.
        expect(calls).toBeLessThanOrEqual(POLL_MAX_MS / POLL_INTERVAL_MS);
    });

    it('does not poll once the derivative is ready', async () => {
        const refresh = vi.fn();
        const view = mount('pending', refresh);

        vi.advanceTimersByTime(POLL_INTERVAL_MS);
        view.status.value = 'ready';
        await nextTick();
        refresh.mockClear();

        vi.advanceTimersByTime(POLL_INTERVAL_MS * 5);

        expect(refresh).not.toHaveBeenCalled();
    });

    it('never polls a file that is not pending', async () => {
        const refresh = vi.fn();
        mount('failed', refresh);

        vi.advanceTimersByTime(POLL_INTERVAL_MS * 5);

        expect(refresh).not.toHaveBeenCalled();
    });

    it('restarts the 2-minute clock when another file is shown', async () => {
        const refresh = vi.fn();
        const view = mount('pending', refresh);

        vi.advanceTimersByTime(POLL_MAX_MS - POLL_INTERVAL_MS);
        view.key.value = 'file-b';
        await nextTick();
        vi.advanceTimersByTime(POLL_INTERVAL_MS * 2);

        expect(view.gaveUp()).toBe(false);
    });
});
