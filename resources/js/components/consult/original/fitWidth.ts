import { observeResize } from '@/composables/useElementResize';

/**
 * Keep a viewer's "fit width" in step with its container. `apply` receives
 * the usable width (container minus `padding`) once at start and then,
 * debounced, each time it really changes: sub-2px jitter (a scrollbar
 * appearing) and collapsed containers are ignored, so a viewer does not
 * re-render its pages for nothing. Whether to re-lay out (fit) or keep a
 * manual zoom is the caller's decision.
 */
export function watchFitWidth(
    element: HTMLElement,
    apply: (width: number) => void,
    options: { padding?: number; delay?: number } = {},
): () => void {
    const padding = options.padding ?? 0;
    let last = -1;

    const offer = (raw: number): void => {
        const width = Math.floor(raw - padding);

        if (width < 100 || Math.abs(width - last) < 2) {
            return;
        }

        last = width;
        apply(width);
    };

    // Before the observer's first callback, so the first layout is right.
    offer(element.offsetWidth);

    return observeResize(
        element,
        () => offer(element.offsetWidth),
        options.delay,
    );
}
