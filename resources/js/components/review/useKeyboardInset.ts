import { onBeforeUnmount, onMounted } from 'vue';

const PROPERTY = '--keyboard-inset';

/**
 * How much of the bottom of the layout viewport an on-screen keyboard
 * covers, published as `--keyboard-inset` on <html>.
 *
 * A dialog is a bottom sheet on a phone, and a `fixed bottom-0` sheet sits
 * under the keyboard on browsers that overlay it instead of resizing the
 * page (iOS Safari). A sheet that lifts itself by this inset keeps its
 * footer — the confirm button — in view while the officer types.
 */
export function useKeyboardInset(): void {
    function update(): void {
        const viewport = window.visualViewport;

        if (!viewport) {
            return;
        }

        const inset = Math.max(
            0,
            Math.round(
                window.innerHeight - viewport.height - viewport.offsetTop,
            ),
        );

        document.documentElement.style.setProperty(PROPERTY, `${inset}px`);
    }

    onMounted(() => {
        window.visualViewport?.addEventListener('resize', update);
        window.visualViewport?.addEventListener('scroll', update);
        update();
    });

    onBeforeUnmount(() => {
        window.visualViewport?.removeEventListener('resize', update);
        window.visualViewport?.removeEventListener('scroll', update);
        document.documentElement.style.removeProperty(PROPERTY);
    });
}
