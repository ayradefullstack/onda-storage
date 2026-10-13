import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';
import { prefersReducedMotion } from '@/composables/useReducedMotion';

export interface RevealOptions {
    /** Share of the element that must be visible before it reveals. */
    threshold?: number;
    /** Skip the effect entirely (e.g. a list longer than 50 rows). */
    disabled?: boolean;
}

/** Lists longer than this are shown as they are: no per-row observers. */
export const REVEAL_MAX_ITEMS = 50;

const HIDDEN = 'motion-reveal';
const SHOWN = 'is-revealed';

/**
 * Fade an element up the first time it enters the viewport, once.
 *
 * Does nothing under prefers-reduced-motion, when disabled, or where
 * IntersectionObserver is missing: the element is then never hidden, so
 * content cannot get stuck invisible.
 */
export function observeReveal(
    element: Element,
    options: RevealOptions = {},
): () => void {
    if (
        options.disabled === true ||
        prefersReducedMotion() ||
        typeof IntersectionObserver === 'undefined'
    ) {
        return () => {};
    }

    element.classList.add(HIDDEN);

    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) {
                    continue;
                }

                entry.target.classList.add(SHOWN);
                observer.unobserve(entry.target);
                observer.disconnect();
            }
        },
        { threshold: options.threshold ?? 0.15 },
    );

    observer.observe(element);

    return () => observer.disconnect();
}

/** Component form: bind the returned ref to the element to reveal. */
export function useReveal<T extends Element = HTMLElement>(
    options: RevealOptions = {},
): Ref<T | null> {
    const target = ref<T | null>(null) as Ref<T | null>;
    let stop = (): void => {};

    onMounted(() => {
        if (target.value !== null) {
            stop = observeReveal(target.value, options);
        }
    });
    onBeforeUnmount(() => stop());

    return target;
}

type RevealElement = Element & { __stopReveal?: () => void };

/** Directive form, for items of a v-for: `v-reveal` or `v-reveal="false"`. */
export const vReveal = {
    mounted(el: RevealElement, binding: { value?: boolean }): void {
        el.__stopReveal = observeReveal(el, {
            disabled: binding.value === false,
        });
    },
    beforeUnmount(el: RevealElement): void {
        el.__stopReveal?.();
    },
};
