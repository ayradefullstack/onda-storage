import { computed, ref } from 'vue';
import { hasArabicLetters } from '@/lib/authInput';

/**
 * Keyboard-layout mistakes that are invisible in a masked field: Caps Lock on,
 * or an Arabic layout active while typing a Latin credential. Purely advisory
 * — nothing is blocked.
 *
 * Wire `onKey` to keydown/keyup, `onValue` to input, and `reset` to blur.
 */
export function useKeyboardHints() {
    const capsLock = ref(false);
    const arabic = ref(false);

    function onKey(event: KeyboardEvent): void {
        // Only real key events carry the modifier state.
        if (typeof event.getModifierState === 'function') {
            capsLock.value = event.getModifierState('CapsLock');
        }
    }

    function onValue(value: string): void {
        arabic.value = hasArabicLetters(value);
    }

    function reset(): void {
        capsLock.value = false;
    }

    /** Which hint to show, if any. Arabic wins: it explains a login failure. */
    const hint = computed<'arabic' | 'caps' | null>(() =>
        arabic.value ? 'arabic' : capsLock.value ? 'caps' : null,
    );

    return { capsLock, arabic, hint, onKey, onValue, reset };
}
