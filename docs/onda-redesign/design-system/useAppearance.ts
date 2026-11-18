// Proposed for resources/js/composables/useAppearance.ts (Phase 5 — not yet applied).
// If the app already ships a useAppearance composable (Laravel Vue starter kit does),
// keep its API and only align the class names / storage key with onda-tokens.css.
import { onMounted, ref } from 'vue';

export type Appearance = 'light' | 'dark' | 'system';
const STORAGE_KEY = 'appearance';
const media = () => window.matchMedia('(prefers-color-scheme: dark)');

function resolve(value: Appearance): 'light' | 'dark' {
    return value === 'system' ? (media().matches ? 'dark' : 'light') : value;
}

export function applyAppearance(value: Appearance): void {
    if (typeof window === 'undefined') return;
    const root = document.documentElement;
    const resolved = resolve(value);
    root.classList.toggle('dark', resolved === 'dark');
    root.classList.toggle('light', resolved === 'light');
    root.dataset.appearance = value; // remembers the *preference* ('system' stays 'system')
}

function read(): Appearance {
    try {
        const v = localStorage.getItem(STORAGE_KEY);
        return v === 'light' || v === 'dark' || v === 'system' ? v : 'system';
    } catch {
        return 'system';
    }
}

/** Call once in app.ts before mounting (and mirror it inline in app.blade.php <head> to avoid a flash). */
export function initializeTheme(): void {
    if (typeof window === 'undefined') return;
    applyAppearance(read());
    media().addEventListener('change', () => {
        if (read() === 'system') applyAppearance('system');
    });
}

export function useAppearance() {
    const appearance = ref<Appearance>('system');
    onMounted(() => (appearance.value = read()));

    function updateAppearance(value: Appearance) {
        appearance.value = value;
        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch {
            /* private mode: still apply for this page view */
        }
        // Optional: also persist in a cookie so Laravel can render the right class server-side.
        document.cookie = `${STORAGE_KEY}=${value};path=/;max-age=31536000;SameSite=Lax`;
        applyAppearance(value);
    }

    return { appearance, updateAppearance };
}
