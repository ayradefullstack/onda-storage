/**
 * A small pool of long-lived chunk-slicing workers, replacing both the old
 * one-Worker-per-chunk spawn and any single shared FIFO worker.
 *
 * Tasks are tagged with the upload session uuid (the worker echoes it back,
 * suffixed with a sequence number so a resumed chunk can never be confused
 * with its aborted predecessor). The queue is FIFO, but what arrives in it is
 * already in the scheduler's round-robin order and is bounded by
 * `maxInFlight`, so the pool adds no unfairness of its own: a large file
 * cannot flood it.
 *
 * Memory: every task holds at most one 8 MiB buffer, and a buffer exists only
 * between "sliced" and the chunk request settling — `maxInFlight × 8 MiB`.
 * `File.slice()` is lazy; the worker reads exactly one chunk, never the file.
 */
import type { WorkerResponse, WorkerSliceRequest } from '@/types/upload';
import ChunkerWorker from '../../workers/chunker.worker.ts?worker&inline';

/**
 * `?worker&inline` bundles the worker as a same-origin blob in the production
 * build. In dev Vite still points the Worker at the dev-server URL, which a
 * browser refuses to construct cross-origin (`SecurityError`), so dev fetches
 * the worker's transformed source and builds the Worker from a Blob. That only
 * works because the worker file has no runtime imports — see its header.
 */
let devWorkerBlobUrl: Promise<string> | null = null;

async function resolveDevWorkerBlobUrl(): Promise<string> {
    devWorkerBlobUrl ??= (async () => {
        const workerModuleUrl = new URL(
            '../../workers/chunker.worker.ts',
            import.meta.url,
        );
        const response = await fetch(workerModuleUrl);

        if (!response.ok) {
            throw new Error(
                `Could not load the upload worker (HTTP ${response.status}).`,
            );
        }

        const source = await response.text();

        return URL.createObjectURL(
            new Blob([source], { type: 'text/javascript' }),
        );
    })();

    return devWorkerBlobUrl;
}

async function createChunkerWorker(): Promise<Worker> {
    if (import.meta.env.PROD) {
        return new ChunkerWorker();
    }

    return new Worker(await resolveDevWorkerBlobUrl(), { type: 'module' });
}

/** `min(cores - 1, 3)`, never below 1. */
export function defaultPoolSize(): number {
    const cores =
        typeof navigator !== 'undefined' && navigator.hardwareConcurrency
            ? navigator.hardwareConcurrency
            : 2;

    return Math.max(1, Math.min(cores - 1, 3));
}

interface Task {
    sessionUuid: string;
    index: number;
    blob: Blob;
    signal: AbortSignal;
    resolve: (response: WorkerResponse) => void;
    reject: (error: unknown) => void;
}

interface Slot {
    worker: Worker;
    current: { requestId: string; task: Task } | null;
}

function abortError(): DOMException {
    return new DOMException('Aborted', 'AbortError');
}

export function createWorkerPool(size: number = defaultPoolSize()) {
    const slots: Slot[] = [];
    const queue: Task[] = [];
    let creating = 0;
    let sequence = 0;

    function failTask(task: Task, error: unknown): void {
        task.reject(error);
    }

    function retire(slot: Slot, error: unknown): void {
        const index = slots.indexOf(slot);

        if (index !== -1) {
            slots.splice(index, 1);
        }

        slot.worker.terminate();

        if (slot.current) {
            failTask(slot.current.task, error);
            slot.current = null;
        }

        dispatch();
    }

    function run(slot: Slot, task: Task): void {
        if (task.signal.aborted) {
            failTask(task, abortError());
            dispatch();

            return;
        }

        const requestId = `${task.sessionUuid}#${++sequence}`;
        slot.current = { requestId, task };

        const message: WorkerSliceRequest = {
            type: 'slice',
            fileId: requestId,
            index: task.index,
            blob: task.blob,
        };
        slot.worker.postMessage(message);
    }

    function attach(worker: Worker): Slot {
        const slot: Slot = { worker, current: null };

        worker.addEventListener(
            'message',
            (event: MessageEvent<WorkerResponse>) => {
                const current = slot.current;

                if (!current || event.data.fileId !== current.requestId) {
                    return;
                }

                slot.current = null;

                if (current.task.signal.aborted) {
                    failTask(current.task, abortError());
                } else {
                    // Hand the file's own id back, not the pool's tag.
                    current.task.resolve({
                        ...event.data,
                        fileId: current.task.sessionUuid,
                    });
                }

                dispatch();
            },
        );

        worker.addEventListener('error', (event: ErrorEvent) => {
            retire(
                slot,
                new Error(
                    event.message || 'Worker error while slicing a chunk.',
                ),
            );
        });

        slots.push(slot);

        return slot;
    }

    function dispatch(): void {
        while (queue.length > 0) {
            const idle = slots.find((slot) => slot.current === null);

            if (idle) {
                run(idle, queue.shift() as Task);

                continue;
            }

            if (slots.length + creating < size) {
                creating++;
                const task = queue.shift() as Task;

                void createChunkerWorker().then(
                    (worker) => {
                        creating--;
                        run(attach(worker), task);
                    },
                    (error: unknown) => {
                        creating--;
                        failTask(task, error);
                        dispatch();
                    },
                );

                continue;
            }

            return;
        }
    }

    return {
        slice(
            sessionUuid: string,
            index: number,
            blob: Blob,
            signal: AbortSignal,
        ): Promise<WorkerResponse> {
            return new Promise((resolve, reject) => {
                queue.push({
                    sessionUuid,
                    index,
                    blob,
                    signal,
                    resolve,
                    reject,
                });
                dispatch();
            });
        },
        queued: () => queue.length,
        size: () => slots.length,
    };
}

export type WorkerPool = ReturnType<typeof createWorkerPool>;
