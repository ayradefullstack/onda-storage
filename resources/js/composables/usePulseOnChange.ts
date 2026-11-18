import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * True for a moment after `source` changes (never on first render): bind it
 * to the `motion-pulse-once` class so a badge whose status just changed — a
 * deposit becoming ready, an oeuvre accepted — gets one soft highlight.
 */
export function usePulseOnChange(
    source: () => unknown,
    duration = 900,
): Ref<boolean> {
    const pulsing = ref(false);
    let timer: ReturnType<typeof setTimeout> | null = null;

    watch(source, () => {
        if (timer !== null) {
            clearTimeout(timer);
        }

        pulsing.value = true;
        timer = setTimeout(() => {
            pulsing.value = false;
            timer = null;
        }, duration);
    });

    onBeforeUnmount(() => {
        if (timer !== null) {
            clearTimeout(timer);
        }
    });

    return pulsing;
}
