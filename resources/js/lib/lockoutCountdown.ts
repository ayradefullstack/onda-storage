/**
 * Pure helpers for the account-lockout countdown (no Vue, no i18n).
 *
 * Clock model: a deadline stores TWO absolute instants taken when the server's
 * "seconds remaining" arrived, one on the monotonic clock (immune to the user
 * changing the system time) and one on the wall clock (keeps running while a
 * laptop sleeps, when the monotonic clock may stall). The time left is the
 * smaller of the two, so neither a wall-clock change nor a suspended timer can
 * make the countdown last longer than the server's lockout. The remaining time
 * is always recomputed from the clocks, never decremented per tick.
 */
export type LockoutClock = { mono(): number; wall(): number };

// Read lazily on every call so tests can install fake timers after import.
export const systemClock: LockoutClock = {
    mono: () => performance.now(),
    wall: () => Date.now(),
};

export const MAX_LOCKOUT_SECONDS = 86_400;
export const TICK_MS = 250;
/** A bell is only worth ringing if expiry is noticed this soon after it. */
export const BELL_GRACE_MS = 90_000;

export type LockoutDeadline = {
    id: number;
    monoDeadline: number;
    wallDeadline: number;
    totalSeconds: number;
};

export function canonicalLoginEmail(raw: string): string {
    return raw.trim().toLowerCase();
}

export function createDeadline(
    seconds: number,
    clock: LockoutClock,
    id: number,
): LockoutDeadline | null {
    if (!Number.isFinite(seconds) || seconds <= 0) {
        return null;
    }

    const totalSeconds = Math.min(seconds, MAX_LOCKOUT_SECONDS);

    return {
        id,
        monoDeadline: clock.mono() + totalSeconds * 1000,
        wallDeadline: clock.wall() + totalSeconds * 1000,
        totalSeconds,
    };
}

export function remainingMs(
    deadline: LockoutDeadline,
    clock: LockoutClock,
): number {
    return Math.max(
        0,
        Math.min(
            deadline.monoDeadline - clock.mono(),
            deadline.wallDeadline - clock.wall(),
        ),
    );
}

/**
 * How late `clock` is relative to the deadline, in ms (negative = early).
 * The wall clock catches a laptop sleep, when the monotonic clock pauses; if
 * the wall clock moves backwards the monotonic term wins. A forward wall jump
 * larger than BELL_GRACE_MS while awake silences the bell: acceptable, it
 * fails quiet.
 */
export function lateByMs(
    deadline: LockoutDeadline,
    clock: LockoutClock,
): number {
    return Math.max(
        clock.mono() - deadline.monoDeadline,
        clock.wall() - deadline.wallDeadline,
    );
}

export function remainingSeconds(
    deadline: LockoutDeadline,
    clock: LockoutClock,
): number {
    return Math.ceil(remainingMs(deadline, clock) / 1000);
}

const pad = (n: number): string => String(n).padStart(2, '0');

/** "mm:ss" below one hour, "h:mm:ss" from one hour. Western digits. */
export function formatClock(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds <= 0) {
        return '00:00';
    }

    const total = Math.floor(seconds);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;

    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
}

export type LockoutRegistry = {
    /** Canonicalises the email; replaces any entry with a NEW id. */
    record(email: string, seconds: number): LockoutDeadline | null;
    get(email: string): LockoutDeadline | undefined;
    delete(email: string): void;
    clear(): void;
    readonly size: number;
};

/** In-memory only: nothing is written to any browser storage. */
export function createLockoutRegistry(clock: LockoutClock): LockoutRegistry {
    const entries = new Map<string, LockoutDeadline>();
    let nextId = 1;

    return {
        record(email, seconds) {
            const key = canonicalLoginEmail(email);
            const deadline = createDeadline(seconds, clock, nextId++);

            if (deadline === null) {
                entries.delete(key);

                return null;
            }

            entries.set(key, deadline);

            return deadline;
        },
        get: (email) => entries.get(canonicalLoginEmail(email)),
        delete(email) {
            entries.delete(canonicalLoginEmail(email));
        },
        clear() {
            entries.clear();
        },
        get size() {
            return entries.size;
        },
    };
}
