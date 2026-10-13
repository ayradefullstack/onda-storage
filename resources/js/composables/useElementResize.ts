import { onBeforeUnmount, onMounted } from 'vue';
import type { Ref } from 'vue';

export interface ElementSize {
    width: number;
    height: number;
}

/**
 * Call `onResize` with an element's content box whenever it changes size,
 * debounced so a window drag re-lays out a viewer once, not sixty times a
 * second. The first measurement is delivered immediately.
 */
export function observeResize(
    element: Element,
    onResize: (size: ElementSize) => void,
    delay = 120,
): () => void {
    if (typeof ResizeObserver === 'undefined') {
        return () => {};
    }

    let timer: ReturnType<typeof setTimeout> | null = null;
    let first = true;

    const observer = new ResizeObserver((entries) => {
        const box = entries[entries.length - 1]?.contentRect;

        if (box === undefined) {
            return;
        }

        const size = { width: box.width, height: box.height };

        if (first) {
            first = false;
            onResize(size);

            return;
        }

        if (timer !== null) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => {
            timer = null;
            onResize(size);
        }, delay);
    });

    observer.observe(element);

    return () => {
        if (timer !== null) {
            clearTimeout(timer);
        }

        observer.disconnect();
    };
}

export function useElementResize(
    target: Ref<Element | null>,
    onResize: (size: ElementSize) => void,
    delay = 120,
): void {
    let stop = (): void => {};

    onMounted(() => {
        if (target.value !== null) {
            stop = observeResize(target.value, onResize, delay);
        }
    });
    onBeforeUnmount(() => stop());
}
