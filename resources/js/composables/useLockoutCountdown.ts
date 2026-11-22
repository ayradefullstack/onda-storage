import {
    computed,
    getCurrentInstance,
    getCurrentScope,
    onMounted,
    onScopeDispose,
    ref,
    watch,
} from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { createLockoutBell } from '@/lib/lockoutBell';
import type { LockoutBell } from '@/lib/lockoutBell';
import {
    BELL_GRACE_MS,
    TICK_MS,
    canonicalLoginEmail,
    createLockoutRegistry,
    formatClock,
    lateByMs,
    remainingMs,
    remainingSeconds as secondsLeft,
    systemClock,
} from '@/lib/lockoutCountdown';
import type { LockoutClock, LockoutDeadline } from '@/lib/lockoutCountdown';

export type LockoutCountdownOptions = {
    /** The email currently typed in the form. */
    email: () => string;
    clock?: LockoutClock;
    bell?: LockoutBell;
    onExpire?: (email: string) => void;
};

export type LockoutCountdown = {
    record(
        submittedEmail: string,
        seconds: number | null,
    ): { started: boolean; displayed: boolean };
    isLocked(email: string): boolean;
    active: Ref<boolean>;
    remainingSeconds: Ref<number>;
    clockText: ComputedRef<string>;
    ready: Ref<boolean>;
    clearReady(): void;
    soundSupported: boolean;
    soundEnabled: Ref<boolean>;
    soundBlocked: Ref<boolean>;
    setSound(on: boolean): void;
    dispose(): void;
};

/**
 * Per-email lockout countdown for the login form.
 *
 * Only the entry of the address currently in the field is displayed; entries
 * for other addresses are kept (so switching back shows the ORIGINAL deadline)
 * but expire silently. The countdown never submits anything and never touches
 * browser storage.
 */
export function useLockoutCountdown(
    options: LockoutCountdownOptions,
): LockoutCountdown {
    const clock = options.clock ?? systemClock;
    const bell = options.bell ?? createLockoutBell();
    const registry = createLockoutRegistry(clock);

    const active = ref(false);
    const remainingSeconds = ref(0);
    const ready = ref(false);
    const soundEnabled = ref(false);
    const soundBlocked = ref(false);
    const clockText = computed(() => formatClock(remainingSeconds.value));

    let displayed: LockoutDeadline | null = null;
    let displayedEmail = '';
    let timer: ReturnType<typeof setInterval> | null = null;
    let disposed = false;

    const currentEmail = (): string => canonicalLoginEmail(options.email());

    function stopInterval(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    function hide(): void {
        displayed = null;
        displayedEmail = '';
        remainingSeconds.value = 0;
        active.value = false;
        stopInterval();
    }

    /** Shows the entry of `email` if it is still running, else drops it quietly. */
    function show(email: string): void {
        const entry = registry.get(email);

        if (entry === undefined) {
            hide();

            return;
        }

        if (remainingMs(entry, clock) <= 0) {
            // Expired while it was not on screen: never announced, never rung.
            registry.delete(email);
            hide();

            return;
        }

        displayed = entry;
        displayedEmail = email;
        remainingSeconds.value = secondsLeft(entry, clock);
        active.value = true;

        if (timer === null) {
            timer = setInterval(tick, TICK_MS);
        }
    }

    function expire(deadline: LockoutDeadline, email: string): void {
        hide();
        ready.value = true;

        if (registry.get(email)?.id === deadline.id) {
            registry.delete(email);
        }

        options.onExpire?.(email);

        if (soundEnabled.value && lateByMs(deadline, clock) <= BELL_GRACE_MS) {
            void bell.ring().then((rang) => {
                if (!rang && !disposed) {
                    soundBlocked.value = true;
                }
            });
        }
    }

    /** Recomputes from the clocks; a delayed or missed tick just catches up. */
    function tick(): void {
        if (displayed === null) {
            return;
        }

        if (remainingMs(displayed, clock) > 0) {
            remainingSeconds.value = secondsLeft(displayed, clock);

            return;
        }

        expire(displayed, displayedEmail);
    }

    function record(submittedEmail: string, seconds: number | null) {
        const key = canonicalLoginEmail(submittedEmail);
        const entry = seconds === null ? null : registry.record(key, seconds);
        const isCurrent = key === currentEmail();

        if (entry === null) {
            registry.delete(key);

            if (isCurrent) {
                hide();
            }

            return { started: false, displayed: false };
        }

        if (isCurrent) {
            ready.value = false;
            show(key);
        }

        return { started: true, displayed: isCurrent };
    }

    function isLocked(email: string): boolean {
        tick();

        const entry = registry.get(email);

        return entry !== undefined && remainingMs(entry, clock) > 0;
    }

    function setSound(on: boolean): void {
        if (on) {
            soundEnabled.value = true;
            soundBlocked.value = false;
            void bell.arm().then((result) => {
                if (result !== 'ready' && !disposed) {
                    soundBlocked.value = true;
                }
            });

            return;
        }

        soundEnabled.value = false;
        void bell.disarm();
    }

    watch(currentEmail, (email) => {
        ready.value = false;
        show(email);
    });

    // A background tab throttles timers, so catch up as soon as it is seen.
    // No browser global is touched during setup (the notice must never render
    // on first paint: soundSupported differs server vs client); listeners are
    // attached on mount, or immediately when there is no component instance.
    let attached = false;

    function attach(): void {
        if (disposed || attached) {
            return;
        }

        document.addEventListener('visibilitychange', tick);
        window.addEventListener('pageshow', tick);
        window.addEventListener('focus', tick);
        attached = true;
    }

    function dispose(): void {
        if (disposed) {
            return;
        }

        disposed = true;
        stopInterval();

        if (attached) {
            document.removeEventListener('visibilitychange', tick);
            window.removeEventListener('pageshow', tick);
            window.removeEventListener('focus', tick);
            attached = false;
        }

        void bell.disarm();
        registry.clear();
    }

    if (getCurrentInstance()) {
        onMounted(attach);
    } else if (
        typeof document !== 'undefined' &&
        typeof window !== 'undefined'
    ) {
        attach();
    }

    if (getCurrentScope()) {
        onScopeDispose(dispose);
    }

    return {
        record,
        isLocked,
        active,
        remainingSeconds,
        clockText,
        ready,
        clearReady: () => {
            ready.value = false;
        },
        soundSupported: bell.supported,
        soundEnabled,
        soundBlocked,
        setSound,
        dispose,
    };
}
