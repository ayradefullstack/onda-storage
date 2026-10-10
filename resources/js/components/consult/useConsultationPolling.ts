import { onScopeDispose, ref, watch } from 'vue';
import type { Ref } from 'vue';

export const POLL_INTERVAL_MS = 5_000;
export const POLL_MAX_MS = 120_000;

/**
 * While a derivative is `pending`, call `refresh` every 5 s for at most
 * 2 minutes, then stop and report `gaveUp` ("still preparing, refresh
 * later"). The parent decides HOW to refresh (an Inertia partial reload on
 * the review page, a JSON fetch for the side Viewer); this only owns the
 * timing, so both surfaces behave identically.
 *
 * `resetKey` restarts the clock when the viewer is pointed at another file.
 */
export function useConsultationPolling(
    status: Ref<string | undefined>,
    resetKey: Ref<unknown>,
    refresh: () => void | Promise<void>,
) {
    const gaveUp = ref(false);
    let timer: ReturnType<typeof setInterval> | null = null;
    let startedAt = 0;

    function stop(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    function start(): void {
        stop();
        gaveUp.value = false;
        startedAt = Date.now();

        timer = setInterval(() => {
            if (Date.now() - startedAt >= POLL_MAX_MS) {
                stop();
                gaveUp.value = true;

                return;
            }

            void refresh();
        }, POLL_INTERVAL_MS);
    }

    watch(
        [status, resetKey],
        ([next, key], [previous, previousKey]) => {
            if (next !== 'pending') {
                stop();
                gaveUp.value = false;

                return;
            }

            // Already polling this file: keep the original clock.
            if (
                previous === 'pending' &&
                key === previousKey &&
                timer !== null
            ) {
                return;
            }

            start();
        },
        { immediate: true },
    );

    // A component's scope is disposed on unmount; tests use a bare effectScope.
    onScopeDispose(stop);

    return { gaveUp };
}
