import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * True once the page (or a given scroll container) has moved past `offset`
 * pixels. Drives the soft shadow under sticky headers and the back-to-top
 * button. One passive listener, coalesced per frame.
 */
export function useScrolled(
    offset: number | (() => number) = 4,
    container?: Ref<HTMLElement | null>,
): Ref<boolean> {
    const scrolled = ref(false);
    let frame = 0;
    let element: HTMLElement | null = null;

    const read = (): void => {
        frame = 0;
        const limit = typeof offset === 'function' ? offset() : offset;

        scrolled.value =
            (element === null ? window.scrollY : element.scrollTop) > limit;
    };

    const onScroll = (): void => {
        if (frame === 0) {
            frame = requestAnimationFrame(read);
        }
    };

    onMounted(() => {
        element = container?.value ?? null;
        (element ?? window).addEventListener('scroll', onScroll, {
            passive: true,
        });
        read();
    });

    onBeforeUnmount(() => {
        (element ?? window).removeEventListener('scroll', onScroll);

        if (frame !== 0) {
            cancelAnimationFrame(frame);
        }
    });

    return scrolled;
}
