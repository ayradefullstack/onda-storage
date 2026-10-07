/**
 * The one upload scheduler. Pure (no Vue, no fetch, no Worker): it only
 * decides *which file's next chunk runs next*, so it can be driven by a fake
 * transport in tests and by the real one in the store.
 *
 * Rules:
 * - at most `maxInFlight` chunk jobs run across ALL files;
 * - at most `perFileInFlight` run for any ONE file;
 * - when a pool slot frees, the next job comes from the next file in the
 *   ring after the one that was served last — never from "the file with the
 *   most chunks". Small files therefore finish on their own while a large
 *   one keeps progressing, with no priority-by-size.
 *
 * - INITS HAVE PRIORITY (`runPriority`): an init is one tiny request, and a
 *   file cannot join the rotation until it has one. A pending init is
 *   dispatched before the next chunk whenever a slot frees, and when the
 *   chunk pool is full it may use ONE reserved extra slot, so a small
 *   file's init never waits behind a large file's 8 MiB chunk. The total
 *   stays bounded at `maxInFlight + 1` requests.
 *
 * A source is asked for work with `claim()`, which must synchronously mark
 * the chunk as taken (so two pumps can never hand out the same chunk) and
 * return the job that uploads it, or `null` when it has nothing runnable
 * (nothing pending, or the file is paused). Jobs must not reject: a failing
 * chunk is that file's own business and is handled inside the job, which is
 * what keeps one file's failure from touching the others.
 */
export type ChunkJob = () => Promise<void>;

export interface ChunkSource {
    claim(): ChunkJob | null;
}

export interface SchedulerLimits {
    maxInFlight: number;
    perFileInFlight: number;
}

/** Inits allowed at once, and the one extra slot they may use beyond the pool. */
export const PRIORITY_CAP = 3;
export const PRIORITY_RESERVE = 1;

export const DEFAULT_LIMITS: SchedulerLimits = {
    maxInFlight: 3,
    perFileInFlight: 2,
};

export function createScheduler(getLimits: () => SchedulerLimits) {
    const ring: string[] = [];
    const sources = new Map<string, ChunkSource>();
    const inFlightByFile = new Map<string, number>();
    let inFlightTotal = 0;
    let cursor = 0;
    let pumping = false;
    const priorityQueue: (() => Promise<void>)[] = [];
    let priorityRunning = 0;

    function limits(): SchedulerLimits {
        const { maxInFlight, perFileInFlight } = getLimits();

        return {
            maxInFlight: Math.max(1, Math.floor(maxInFlight)),
            perFileInFlight: Math.max(1, Math.floor(perFileInFlight)),
        };
    }

    /** Hands out at most one job: the next eligible file after the cursor. */
    function dispatchOne(max: SchedulerLimits): boolean {
        for (let step = 0; step < ring.length; step++) {
            const position = (cursor + step) % ring.length;
            const id = ring[position] as string;

            if ((inFlightByFile.get(id) ?? 0) >= max.perFileInFlight) {
                continue;
            }

            const job = sources.get(id)?.claim();

            if (!job) {
                continue;
            }

            // The file just served goes to the back of the rotation.
            cursor = (position + 1) % ring.length;
            inFlightByFile.set(id, (inFlightByFile.get(id) ?? 0) + 1);
            inFlightTotal++;

            void job()
                .catch(() => {
                    // Jobs own their failures; this only keeps the pool's
                    // accounting correct if one ever throws anyway.
                })
                .finally(() => {
                    inFlightTotal--;
                    inFlightByFile.set(
                        id,
                        Math.max(0, (inFlightByFile.get(id) ?? 1) - 1),
                    );
                    pump();
                });

            return true;
        }

        return false;
    }

    function startPriority(): void {
        const job = priorityQueue.shift() as () => Promise<void>;

        priorityRunning++;
        inFlightTotal++;

        void job()
            .catch(() => {})
            .finally(() => {
                priorityRunning--;
                inFlightTotal--;
                pump();
            });
    }

    function pump(): void {
        // Re-entrancy guard: a job that settles synchronously must not
        // start a nested pump in the middle of this one's loop.
        if (pumping) {
            return;
        }

        pumping = true;

        try {
            const max = limits();

            // Inits first: before ANY chunk is dispatched.
            while (
                priorityQueue.length > 0 &&
                priorityRunning < PRIORITY_CAP &&
                inFlightTotal < max.maxInFlight + PRIORITY_RESERVE
            ) {
                startPriority();
            }

            while (inFlightTotal < max.maxInFlight && dispatchOne(max)) {
                // keep filling free slots
            }
        } finally {
            pumping = false;
        }
    }

    return {
        /** Joins the rotation immediately; runs within this call. */
        register(id: string, source: ChunkSource): void {
            if (!sources.has(id)) {
                // A newcomer is served next, ahead of the file that just
                // had its turn — so a small file added mid-upload starts
                // at the very next free slot instead of queueing behind
                // the file already running.
                ring.splice(cursor, 0, id);
            }

            sources.set(id, source);
            pump();
        },
        unregister(id: string): void {
            const position = ring.indexOf(id);

            if (position !== -1) {
                ring.splice(position, 1);

                if (position < cursor) {
                    cursor--;
                }

                if (cursor >= ring.length) {
                    cursor = 0;
                }
            }

            sources.delete(id);
        },
        pump,
        /**
         * Runs `task` (an init) with priority over queued chunks; settles
         * with the task's own result. Callers that must wait between
         * attempts (a 429's Retry-After) call it once per attempt, so the
         * wait never holds a slot.
         */
        runPriority<T>(task: () => Promise<T>): Promise<T> {
            return new Promise<T>((resolve, reject) => {
                priorityQueue.push(() => task().then(resolve, reject));
                pump();
            });
        },
        inFlight: () => inFlightTotal,
        inFlightFor: (id: string) => inFlightByFile.get(id) ?? 0,
        isRegistered: (id: string) => sources.has(id),
    };
}

export type UploadScheduler = ReturnType<typeof createScheduler>;

/**
 * Counting limiter for init calls (at most `limit` at once, FIFO beyond
 * that). Separate from the chunk pool: an init is one small request, and
 * must not wait behind another file's chunks.
 */
export function createLimiter(limit: number) {
    let running = 0;
    const waiting: (() => void)[] = [];

    return async function run<T>(task: () => Promise<T>): Promise<T> {
        if (running >= limit) {
            // The slot is handed over by the finishing task without ever dropping
            // `running`, so a caller arriving in between cannot overtake.
            await new Promise<void>((resolve) => waiting.push(resolve));
        } else {
            running++;
        }

        try {
            return await task();
        } finally {
            const next = waiting.shift();

            if (next) {
                next();
            } else {
                running--;
            }
        }
    };
}
