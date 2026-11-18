/**
 * Follows an oeuvre's files through the server pipeline, from the upload's
 * `complete` call to a terminal status, by polling the author's
 * `oeuvres.files.status` endpoint.
 *
 * The rules:
 *
 * - It runs whenever a file of the page is not terminal, whether that was
 *   true when the page loaded (a refresh mid-processing) or became true
 *   later (an upload completing on this page). It is never tied to the
 *   moment the page mounted: arming the poll only on mount, when the oeuvre
 *   had nothing in flight, is exactly what left the rail frozen on step 4.
 * - Backoff 1.5 s → 3 s → 5 s, then 5 s. It never gives up: after 3 minutes
 *   it slows to 10 s and says so, after 15 minutes to 30 s and offers a
 *   refresh. On cPanel the queue runs from cron, so minutes are normal.
 * - Paused while the tab is hidden; on return it fetches at once.
 * - A file reaching a terminal status triggers ONE `onSettled` call, which
 *   the page uses for its single silent partial reload (file list,
 *   submission gate, slot completeness).
 *
 * Network errors are not terminal: the next tick simply tries again.
 */
import { onScopeDispose, readonly, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type { MediaFileStatus } from '@/types/upload';

export interface LiveFileStatus {
    /** The file's uuid. */
    id: string;
    status: MediaFileStatus;
    /** The rail step key (`upload.step.*`); null once failed/quarantined. */
    stage: string | null;
    /** Locale key of the failure message, when failed or quarantined. */
    error: string | null;
}

export type PollPhase = 'idle' | 'active' | 'slow' | 'stalled';

export const TERMINAL_FILE_STATUSES: readonly MediaFileStatus[] = [
    'ready',
    'failed',
    'quarantined',
];

export const POLL_DELAYS_MS = [1500, 3000, 5000] as const;
export const SLOW_AFTER_MS = 3 * 60_000;
export const SLOW_DELAY_MS = 10_000;
export const STALLED_AFTER_MS = 15 * 60_000;
export const STALLED_DELAY_MS = 30_000;

export const isTerminal = (status: MediaFileStatus): boolean =>
    TERMINAL_FILE_STATUSES.includes(status);

/** The wait before poll number `attempt` (0-based), `elapsedMs` into the loop. */
export function nextPollDelay(attempt: number, elapsedMs: number): number {
    if (elapsedMs >= STALLED_AFTER_MS) {
        return STALLED_DELAY_MS;
    }

    if (elapsedMs >= SLOW_AFTER_MS) {
        return SLOW_DELAY_MS;
    }

    return POLL_DELAYS_MS[Math.min(attempt, POLL_DELAYS_MS.length - 1)];
}

export function phaseFor(elapsedMs: number): PollPhase {
    if (elapsedMs >= STALLED_AFTER_MS) {
        return 'stalled';
    }

    return elapsedMs >= SLOW_AFTER_MS ? 'slow' : 'active';
}

export interface PollClock {
    setTimeout: (callback: () => void, ms: number) => unknown;
    clearTimeout: (handle: unknown) => void;
    now: () => number;
}

type Visibility = Pick<
    Document,
    'visibilityState' | 'addEventListener' | 'removeEventListener'
>;

export interface FileStatusPollOptions {
    /** The status endpoint of the page's oeuvre. */
    url: () => string;
    /** The files the page currently shows (its Inertia prop). */
    files: () => readonly { uuid: string; status: MediaFileStatus }[];
    /** Called once per poll in which at least one file became terminal. */
    onSettled: () => void;
    fetcher?: (
        url: string,
        signal: AbortSignal,
    ) => Promise<{ files: LiveFileStatus[] }>;
    clock?: PollClock;
    document?: Visibility | null;
}

const systemClock: PollClock = {
    setTimeout: (callback, ms) => setTimeout(callback, ms),
    clearTimeout: (handle) =>
        clearTimeout(handle as ReturnType<typeof setTimeout>),
    now: () => Date.now(),
};

async function fetchStatus(
    url: string,
    signal: AbortSignal,
): Promise<{ files: LiveFileStatus[] }> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal,
    });

    if (!response.ok) {
        throw new Error(`status ${response.status}`);
    }

    return (await response.json()) as { files: LiveFileStatus[] };
}

export function useFileStatusPoll(options: FileStatusPollOptions): {
    live: Readonly<Ref<Readonly<Record<string, LiveFileStatus>>>>;
    phase: Readonly<Ref<PollPhase>>;
    running: Readonly<Ref<boolean>>;
    kick: () => void;
    pollNow: () => void;
    stop: () => void;
} {
    const clock = options.clock ?? systemClock;
    const fetcher = options.fetcher ?? fetchStatus;
    const doc =
        options.document === undefined
            ? typeof document === 'undefined'
                ? null
                : document
            : options.document;

    const live = ref<Record<string, LiveFileStatus>>({});
    const phase = ref<PollPhase>('idle');
    const running = ref(false);

    let timer: unknown = null;
    let attempt = 0;
    let startedAt = 0;
    let inFlight: AbortController | null = null;
    let paused = false;

    const hidden = (): boolean => doc?.visibilityState === 'hidden';

    const statusOf = (uuid: string, fallback?: MediaFileStatus) =>
        live.value[uuid]?.status ?? fallback;

    const hasPending = (): boolean =>
        options
            .files()
            .some((file) => !isTerminal(statusOf(file.uuid, file.status)!));

    function clearTimer(): void {
        if (timer !== null) {
            clock.clearTimeout(timer);
            timer = null;
        }
    }

    function schedule(delay: number): void {
        clearTimer();
        timer = clock.setTimeout(() => {
            timer = null;
            void tick();
        }, delay);
    }

    function finish(): void {
        clearTimer();
        running.value = false;
        phase.value = 'idle';
        paused = false;
    }

    async function tick(): Promise<void> {
        if (!running.value || inFlight) {
            return;
        }

        if (hidden()) {
            // Resumed by the visibilitychange listener.
            paused = true;

            return;
        }

        const controller = new AbortController();
        inFlight = controller;

        let pending = true;

        try {
            const { files } = await fetcher(options.url(), controller.signal);

            if (controller.signal.aborted) {
                return;
            }

            const shown = new Map(
                options.files().map((file) => [file.uuid, file.status]),
            );
            const next = { ...live.value };
            let settled = false;

            for (const file of files) {
                const previous = statusOf(file.id, shown.get(file.id));

                if (
                    isTerminal(file.status) &&
                    (previous === undefined || !isTerminal(previous))
                ) {
                    settled = true;
                }

                next[file.id] = file;
            }

            live.value = next;
            pending = files.some((file) => !isTerminal(file.status));

            if (settled) {
                options.onSettled();
            }
        } catch {
            if (controller.signal.aborted) {
                return;
            }
            // A blip: keep polling, the backoff below applies.
        } finally {
            if (inFlight === controller) {
                inFlight = null;
            }
        }

        if (!running.value) {
            return;
        }

        if (!pending) {
            finish();

            return;
        }

        attempt++;
        const elapsed = clock.now() - startedAt;
        phase.value = phaseFor(elapsed);
        schedule(nextPollDelay(attempt, elapsed));
    }

    function start(): void {
        attempt = 0;
        startedAt = clock.now();
        running.value = true;
        phase.value = 'active';
        paused = false;
        schedule(POLL_DELAYS_MS[0]);
    }

    /** An upload just completed on this page: follow it from now on. */
    function kick(): void {
        if (running.value && !inFlight) {
            // Back to the fast cadence for the new file.
            attempt = 0;
            startedAt = clock.now();
            phase.value = 'active';
            schedule(POLL_DELAYS_MS[0]);

            return;
        }

        if (!running.value) {
            start();
        }
    }

    function pollNow(): void {
        if (running.value) {
            clearTimer();
            void tick();
        }
    }

    function stop(): void {
        inFlight?.abort();
        inFlight = null;
        finish();
    }

    function onVisibility(): void {
        if (hidden()) {
            clearTimer();
            paused = running.value;

            return;
        }

        if (running.value && paused) {
            paused = false;
            pollNow();
        }
    }

    doc?.addEventListener('visibilitychange', onVisibility);

    // Starts the loop whenever the page shows a file still in the pipeline:
    // on load, and after the reload that follows an upload's `complete`.
    watch(
        () => hasPending(),
        (pendingNow) => {
            if (pendingNow && !running.value) {
                start();
            }
        },
        { immediate: true },
    );

    // Once the page's own props have caught up with a terminal status, the
    // live copy is no longer needed: the props are authoritative again.
    watch(
        () => options.files(),
        (files) => {
            const next = { ...live.value };
            let changed = false;

            for (const file of files) {
                const entry = next[file.uuid];

                if (
                    entry &&
                    entry.status === file.status &&
                    isTerminal(file.status)
                ) {
                    delete next[file.uuid];
                    changed = true;
                }
            }

            if (changed) {
                live.value = next;
            }
        },
    );

    // Another oeuvre on the same page component: start over.
    watch(
        () => options.url(),
        () => {
            stop();
            live.value = {};

            if (hasPending()) {
                start();
            }
        },
    );

    onScopeDispose(() => {
        stop();
        doc?.removeEventListener('visibilitychange', onVisibility);
    });

    return {
        live: readonly(live) as Readonly<
            Ref<Readonly<Record<string, LiveFileStatus>>>
        >,
        phase: readonly(phase),
        running: readonly(running),
        kick,
        pollNow,
        stop,
    };
}
