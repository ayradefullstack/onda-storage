/**
 * Whether the user asked the system for less motion. Read at call time, not
 * cached: the setting can change while the app is open.
 */
export function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}
