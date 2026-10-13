import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { prefersReducedMotion } from '@/composables/useReducedMotion';

/**
 * A number that counts up to its value once, on first view. Later changes
 * of the source are shown at once: the effect is a greeting, not a ticker.
 * Under reduced motion the value is simply the value.
 */
export function useCountUp(source: () => number, duration = 700): Ref<number> {
    const shown = ref(source());
    let frame = 0;

    onMounted(() => {
        const end = source();

        if (prefersReducedMotion() || !Number.isFinite(end) || end === 0) {
            return;
        }

        const started = performance.now();
        shown.value = 0;

        const tick = (now: number): void => {
            const ratio = Math.min((now - started) / duration, 1);
            const eased = 1 - Math.pow(1 - ratio, 3);

            shown.value = ratio === 1 ? end : Math.round(end * eased);
            frame = ratio === 1 ? 0 : requestAnimationFrame(tick);
        };

        frame = requestAnimationFrame(tick);
    });

    watch(source, (value) => {
        if (frame !== 0) {
            cancelAnimationFrame(frame);
            frame = 0;
        }

        shown.value = value;
    });

    onBeforeUnmount(() => {
        if (frame !== 0) {
            cancelAnimationFrame(frame);
        }
    });

    return shown;
}
