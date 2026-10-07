import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as UploadClientModule from '@/lib/uploadClient';
import { UploadHttpError } from '@/lib/uploadClient';
import type { WorkerSliceRequest } from '@/types/upload';

/**
 * Drives the real store + scheduler + worker pool with a fake transport
 * whose latency the test controls: every chunk request is a gate the test
 * opens by hand, so "which file finishes first" is decided by the test, not
 * by timers.
 */

interface Gate {
    session: string;
    index: number;
    resolve: () => void;
    reject: (error: unknown) => void;
}

// The store only reads the shared `upload` limits from the page; the real
// module is slow to import cold, so a stub keeps first-test timing stable.
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
}));

const initUpload = vi.fn();
const uploadChunk = vi.fn();
const completeUpload = vi.fn();
const abortUploadSession = vi.fn();

let gates: Gate[] = [];
let inFlightTotal = 0;
let peakTotal = 0;
const inFlightBySession = new Map<string, number>();
const peakBySession = new Map<string, number>();
const dispatchOrder: string[] = [];
const bytesBySession = new Map<string, number>();
const sessionOfFile = new Map<string, string>();

vi.mock('@/lib/uploadClient', async (importOriginal) => {
    const actual = await importOriginal<typeof UploadClientModule>();

    return {
        ...actual,
        initUpload: (...args: unknown[]) =>
            (initUpload as (...a: unknown[]) => unknown)(...args),
        uploadChunk: (...args: unknown[]) =>
            (uploadChunk as (...a: unknown[]) => unknown)(...args),
        completeUpload: (...args: unknown[]) =>
            (completeUpload as (...a: unknown[]) => unknown)(...args),
        abortUploadSession: (...args: unknown[]) =>
            (abortUploadSession as (...a: unknown[]) => unknown)(...args),
    };
});

class FakeWorker {
    private listeners: Record<string, ((event: unknown) => void)[]> = {};

    addEventListener(type: string, cb: (event: unknown) => void): void {
        (this.listeners[type] ??= []).push(cb);
    }

    postMessage(message: WorkerSliceRequest): void {
        void (async () => {
            const buffer = await message.blob.arrayBuffer();
            this.listeners.message?.forEach((cb) =>
                cb({
                    data: {
                        type: 'sliced',
                        fileId: message.fileId,
                        index: message.index,
                        buffer,
                        crc32: 'deadbeef',
                    },
                }),
            );
        })();
    }

    terminate(): void {}
}

function installTransport(): void {
    uploadChunk.mockImplementation(
        (
            session: string,
            index: number,
            buffer: ArrayBuffer,
            _crc: string,
            signal?: AbortSignal,
        ) =>
            new Promise((resolve, reject) => {
                inFlightTotal++;
                peakTotal = Math.max(peakTotal, inFlightTotal);
                inFlightBySession.set(
                    session,
                    (inFlightBySession.get(session) ?? 0) + 1,
                );
                peakBySession.set(
                    session,
                    Math.max(
                        peakBySession.get(session) ?? 0,
                        inFlightBySession.get(session)!,
                    ),
                );
                dispatchOrder.push(session);

                const done = () => {
                    inFlightTotal--;
                    inFlightBySession.set(
                        session,
                        inFlightBySession.get(session)! - 1,
                    );
                };

                // Like fetch: an aborted request rejects and frees its gate.
                signal?.addEventListener('abort', () => {
                    const at = gates.findIndex(
                        (g) => g.session === session && g.index === index,
                    );

                    if (at !== -1) {
                        gates.splice(at, 1);
                        done();
                        reject(new DOMException('Aborted', 'AbortError'));
                    }
                });

                gates.push({
                    session,
                    index,
                    resolve: () => {
                        done();
                        bytesBySession.set(
                            session,
                            (bytesBySession.get(session) ?? 0) +
                                buffer.byteLength,
                        );
                        resolve({
                            index,
                            received: 0,
                            total: 0,
                            bytes: bytesBySession.get(session),
                        });
                    },
                    reject: (error) => {
                        done();
                        reject(error);
                    },
                });
            }),
    );
}

/** Each file gets its own session, chunked in 4-byte pieces. */
function installInit(): void {
    initUpload.mockImplementation(async (payload: { filename: string }) => {
        const uuid = `s-${payload.filename}`;
        sessionOfFile.set(payload.filename, uuid);
        const size = filesByName.get(payload.filename)!.size;

        return {
            uuid,
            chunk_size: 4,
            total_chunks: Math.ceil(size / 4),
            received: [],
            expires_at: '2026-01-01T00:00:00Z',
        };
    });
}

const filesByName = new Map<string, File>();

function makeFile(name: string, size: number): File {
    const file = new File([new Uint8Array(size)], name, {
        type: 'application/pdf',
    });
    filesByName.set(name, file);

    return file;
}

async function flush(times = 4): Promise<void> {
    for (let i = 0; i < times; i++) {
        await Promise.resolve();
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

/** Opens the oldest gate belonging to `session`. */
async function open(session: string): Promise<void> {
    const at = gates.findIndex((g) => g.session === session);
    expect(at).toBeGreaterThanOrEqual(0);
    gates.splice(at, 1)[0]!.resolve();
    await flush();
}

async function openAny(): Promise<string> {
    const gate = gates.shift()!;
    gate.resolve();
    await flush();

    return gate.session;
}

function statusOf(
    store: { files: { value: { filename: string; status: string }[] } },
    name: string,
) {
    return store.files.value.find((f) => f.filename === name)?.status;
}

describe('upload store — parallel uploads', () => {
    beforeEach(() => {
        vi.resetModules();
        gates = [];
        inFlightTotal = 0;
        peakTotal = 0;
        inFlightBySession.clear();
        peakBySession.clear();
        dispatchOrder.length = 0;
        bytesBySession.clear();
        sessionOfFile.clear();
        filesByName.clear();
        initUpload.mockReset();
        uploadChunk.mockReset();
        completeUpload.mockReset().mockResolvedValue({
            uuid: 'media',
            status: 'uploading',
        });
        abortUploadSession.mockReset().mockResolvedValue(undefined);
        installInit();
        installTransport();
        vi.stubGlobal('Worker', FakeWorker);
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                text: async () => '// worker source',
            }),
        );
        vi.stubGlobal(
            'URL',
            class extends URL {
                static createObjectURL = vi.fn(() => 'blob:fake-worker-url');
            },
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('lets small files complete while the large file is still uploading, and the large file keeps progressing', async () => {
        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        store.enqueueFile(makeFile('video.pdf', 80), 1); // 20 chunks
        store.enqueueFile(makeFile('small.pdf', 4), 1); // 1 chunk
        store.enqueueFile(makeFile('note.pdf', 8), 1); // 2 chunks
        await flush();

        // All three show activity immediately — nobody is "waiting".
        expect(store.files.value.every((f) => f.status === 'uploading')).toBe(
            true,
        );

        const videoBefore = bytesBySession.get('s-video.pdf') ?? 0;

        // Open only the small files' gates, as they come up.
        for (let i = 0; i < 6; i++) {
            const small = gates.find((g) => g.session !== 's-video.pdf');

            if (small) {
                await open(small.session);
            }
        }

        expect(statusOf(store, 'small.pdf')).toBe('completed');
        expect(statusOf(store, 'note.pdf')).toBe('completed');
        expect(statusOf(store, 'video.pdf')).toBe('uploading');

        // The large file is still being served, and moves forward.
        await open('s-video.pdf');
        expect(bytesBySession.get('s-video.pdf')!).toBeGreaterThan(videoBefore);
        expect(statusOf(store, 'video.pdf')).toBe('uploading');
    });

    it('never exceeds 3 (the default) in flight overall or 2 per file', async () => {
        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        store.enqueueFile(makeFile('a.pdf', 80), 1);
        store.enqueueFile(makeFile('b.pdf', 80), 1);
        store.enqueueFile(makeFile('c.pdf', 80), 1);
        await flush();

        for (let i = 0; i < 30; i++) {
            await openAny();
        }

        expect(peakTotal).toBe(3);

        for (const peak of peakBySession.values()) {
            expect(peak).toBeLessThanOrEqual(2);
        }
    });

    it('round-robins chunk dispatch across the active files', async () => {
        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        store.enqueueFile(makeFile('a.pdf', 80), 1);
        store.enqueueFile(makeFile('b.pdf', 80), 1);
        store.enqueueFile(makeFile('c.pdf', 80), 1);
        await flush();

        for (let i = 0; i < 12; i++) {
            await openAny();
        }

        // Every window of 3 consecutive dispatches past the warm-up touches
        // all three files: nobody is drained first.
        const sample = dispatchOrder.slice(3, 15);
        const counts = new Map<string, number>();
        sample.forEach((s) => counts.set(s, (counts.get(s) ?? 0) + 1));

        expect(counts.size).toBe(3);

        for (const n of counts.values()) {
            expect(n).toBeGreaterThanOrEqual(3);
            expect(n).toBeLessThanOrEqual(5);
        }
    });

    it('starts a file added mid-upload at the next free slot', async () => {
        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        store.enqueueFile(makeFile('big.pdf', 400), 1);
        await flush();
        store.enqueueFile(makeFile('late.pdf', 8), 1);
        await flush();

        await open('s-big.pdf');

        expect(dispatchOrder).toContain('s-late.pdf');
        expect(statusOf(store, 'late.pdf')).toBe('uploading');
    });

    it('a file that fails permanently (413) leaves the rotation and the others carry on', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        store.enqueueFile(makeFile('bad.pdf', 40), 1);
        store.enqueueFile(makeFile('good.pdf', 8), 1);
        await flush();

        const bad = gates.find((g) => g.session === 's-bad.pdf')!;
        gates.splice(gates.indexOf(bad), 1);
        bad.reject(new UploadHttpError(413, { remaining_bytes: 1 }));
        await flush();

        expect(statusOf(store, 'bad.pdf')).toBe('quota_exceeded');

        for (let i = 0; i < 4; i++) {
            const good = gates.find((g) => g.session === 's-good.pdf');

            if (good) {
                await open('s-good.pdf');
            }
        }

        expect(statusOf(store, 'good.pdf')).toBe('completed');
        // Nothing more is ever dispatched for the failed file.
        expect(gates.some((g) => g.session === 's-bad.pdf')).toBe(false);
    });

    it('pause and cancel act on one file only', async () => {
        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        const a = store.enqueueFile(makeFile('a.pdf', 80), 1);
        const b = store.enqueueFile(makeFile('b.pdf', 80), 1);
        const c = store.enqueueFile(makeFile('c.pdf', 80), 1);
        await flush();

        store.pauseFile((a as { id: string }).id);
        await store.cancelFile((b as { id: string }).id);
        await flush();

        expect(statusOf(store, 'a.pdf')).toBe('paused');
        expect(statusOf(store, 'b.pdf')).toBeUndefined();
        expect(statusOf(store, 'c.pdf')).toBe('uploading');
        expect(abortUploadSession).toHaveBeenCalledWith('s-b.pdf');
        void c;

        const before = bytesBySession.get('s-c.pdf') ?? 0;
        await open('s-c.pdf');
        expect(bytesBySession.get('s-c.pdf')!).toBeGreaterThan(before);
    });

    it('runs inits in parallel, at most 3 at once', async () => {
        let active = 0;
        let peak = 0;
        const releases: (() => void)[] = [];

        initUpload.mockImplementation(async (payload: { filename: string }) => {
            active++;
            peak = Math.max(peak, active);
            await new Promise<void>((resolve) => releases.push(resolve));
            active--;

            return {
                uuid: `s-${payload.filename}`,
                chunk_size: 4,
                total_chunks: 1,
                received: [],
                expires_at: '2026-01-01T00:00:00Z',
            };
        });

        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        for (const name of ['1.pdf', '2.pdf', '3.pdf', '4.pdf', '5.pdf']) {
            store.enqueueFile(makeFile(name, 4), 1);
        }

        await flush();
        expect(active).toBe(3);

        while (releases.length > 0) {
            releases.shift()!();
            await flush();
        }

        expect(peak).toBe(3);
    });

    it('retry after chunk failures resumes the SAME session: one init, no re-reservation', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        vi.useFakeTimers({ toFake: ['setTimeout', 'Date'] });

        try {
            const { useUploadStore } = await import('./uploads');
            const store = useUploadStore();

            store.enqueueFile(makeFile('flaky.pdf', 4), 1);
            await vi.advanceTimersByTimeAsync(10);

            // Four failed attempts (backoff 1s, 2s, 4s between them).
            for (let attempt = 0; attempt < 4; attempt++) {
                const gate = gates.shift();
                expect(gate).toBeDefined();
                gate!.reject(new UploadHttpError(500, null));
                await vi.advanceTimersByTimeAsync(9000);
            }

            expect(statusOf(store, 'flaky.pdf')).toBe('failed');
            expect(initUpload).toHaveBeenCalledTimes(1);

            store.resumeFile(store.files.value[0]!.id);
            await vi.advanceTimersByTimeAsync(10);
            gates.shift()!.resolve();
            await vi.advanceTimersByTimeAsync(10);

            expect(statusOf(store, 'flaky.pdf')).toBe('completed');
            expect(initUpload).toHaveBeenCalledTimes(1);
            expect(completeUpload).toHaveBeenCalledWith('s-flaky.pdf');
            expect(abortUploadSession).not.toHaveBeenCalled();
        } finally {
            vi.useRealTimers();
        }
    });

    it('pause then resume reuses the same session', async () => {
        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        const result = store.enqueueFile(makeFile('p.pdf', 40), 1);
        await flush();
        const id = (result as { id: string }).id;

        store.pauseFile(id);
        await flush();
        store.resumeFile(id);
        await flush();

        expect(initUpload).toHaveBeenCalledTimes(1);
        expect(statusOf(store, 'p.pdf')).toBe('uploading');
        expect(abortUploadSession).not.toHaveBeenCalled();
    });

    it('an expired session is aborted first, then replaced by a new one', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const order: string[] = [];
        let sessions = 0;

        abortUploadSession.mockImplementation(async (uuid: string) => {
            order.push(`abort:${uuid}`);
        });
        initUpload.mockImplementation(async () => {
            order.push('init');
            sessions++;

            return {
                uuid: `session-${sessions}`,
                chunk_size: 4,
                total_chunks: 10,
                received: [],
                expires_at: '2026-01-01T00:00:00Z',
            };
        });

        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        store.enqueueFile(makeFile('old.pdf', 40), 1);
        await flush();

        // The server says the session is gone (410) on the first chunk.
        gates.shift()!.reject(new UploadHttpError(410, null));
        await flush();
        expect(statusOf(store, 'old.pdf')).toBe('expired');

        store.resumeFile(store.files.value[0]!.id);
        await flush();

        expect(order).toEqual(['init', 'abort:session-1', 'init']);
        expect(store.files.value[0]!.sessionUuid).toBe('session-2');
        expect(statusOf(store, 'old.pdf')).toBe('uploading');
    });

    it('cancelling mid-upload aborts the session on the server', async () => {
        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        const result = store.enqueueFile(makeFile('mid.pdf', 80), 1);
        await flush();
        await open('s-mid.pdf'); // genuinely mid-upload, not during init

        await store.cancelFile((result as { id: string }).id);

        expect(abortUploadSession).toHaveBeenCalledWith('s-mid.pdf');
        expect(store.files.value).toHaveLength(0);
    });

    it('has no singular "current upload" concept', async () => {
        const { useUploadStore } = await import('./uploads');
        const surface = Object.keys(useUploadStore());

        for (const key of surface) {
            expect(key).not.toMatch(
                /current|active(Upload|File)\b|maxConcurrentFiles/i,
            );
        }
    });

    describe('status mapping', () => {
        beforeEach(() => {
            vi.spyOn(console, 'error').mockImplementation(() => {});
            vi.spyOn(console, 'warn').mockImplementation(() => {});
            vi.useFakeTimers({ toFake: ['setTimeout', 'Date'] });
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        async function start(name: string, size = 4) {
            const { useUploadStore } = await import('./uploads');
            const store = useUploadStore();

            store.enqueueFile(makeFile(name, size), 1);
            await vi.advanceTimersByTimeAsync(10);

            return store;
        }

        const fileOf = (
            store: Awaited<ReturnType<typeof start>>,
            name: string,
        ) => store.files.value.find((f) => f.filename === name)!;

        it('413: stops, and says how much room is left and how much the file needs', async () => {
            initUpload.mockReset().mockRejectedValue(
                new UploadHttpError(413, {
                    error: 'quota_exceeded',
                    remaining_bytes: 400,
                    needed_bytes: 600,
                }),
            );

            const store = await start('quota.pdf');
            await vi.advanceTimersByTimeAsync(60_000);

            const file = fileOf(store, 'quota.pdf');
            expect(file.status).toBe('quota_exceeded');
            expect(file.remainingQuotaBytes).toBe(400);
            expect(file.neededQuotaBytes).toBe(600);
            expect(initUpload).toHaveBeenCalledTimes(1);
        });

        it("422: stops, and keeps the server's own validation message", async () => {
            initUpload.mockReset().mockRejectedValue(
                new UploadHttpError(422, {
                    message: 'The given data was invalid.',
                    errors: {
                        college_oeuvre_file_id: [
                            'This document accepts a single file, and one is already deposited.',
                        ],
                    },
                }),
            );

            const store = await start('full.pdf');
            await vi.advanceTimersByTimeAsync(60_000);

            const file = fileOf(store, 'full.pdf');
            expect(file.status).toBe('failed');
            expect(file.errorCode).toBe('validation');
            expect(file.errorMessage).toBe(
                'This document accepts a single file, and one is already deposited.',
            );
            expect(initUpload).toHaveBeenCalledTimes(1);
        });

        it('429 on init: "server busy", retried by itself after Retry-After, no user action', async () => {
            initUpload.mockRejectedValueOnce(
                new UploadHttpError(429, { message: 'Too Many Attempts.' }, 8),
            );

            const store = await start('busy.pdf');

            // Waiting out the server's Retry-After (8 s), not the 1 s backoff.
            expect(fileOf(store, 'busy.pdf').notice).toBe('serverBusy');
            expect(initUpload).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(7_000);
            expect(initUpload).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(1_500);
            expect(initUpload).toHaveBeenCalledTimes(2);
            expect(fileOf(store, 'busy.pdf').status).toBe('uploading');
            expect(fileOf(store, 'busy.pdf').notice).toBeNull();
        });

        it.each([502, 503, 504])(
            '%i on init: "temporary server problem", retried by itself with backoff',
            async (status) => {
                initUpload.mockRejectedValueOnce(
                    new UploadHttpError(status, null),
                );

                const store = await start('temp.pdf');
                expect(fileOf(store, 'temp.pdf').notice).toBe(
                    'temporaryServer',
                );

                await vi.advanceTimersByTimeAsync(1_500);

                expect(initUpload).toHaveBeenCalledTimes(2);
                expect(fileOf(store, 'temp.pdf').status).toBe('uploading');
                expect(fileOf(store, 'temp.pdf').notice).toBeNull();
            },
        );

        it('a server that stays busy eventually fails the file with the busy message', async () => {
            initUpload
                .mockReset()
                .mockRejectedValue(new UploadHttpError(429, null, 1));

            const store = await start('stuck.pdf');
            await vi.advanceTimersByTimeAsync(10 * 60_000);

            const file = fileOf(store, 'stuck.pdf');
            expect(file.status).toBe('failed');
            expect(file.errorCode).toBe('serverBusy');
            expect(initUpload).toHaveBeenCalledTimes(8);
        });

        it('419: stops with "signed out" and does not retry', async () => {
            initUpload
                .mockReset()
                .mockRejectedValue(new UploadHttpError(419, null));

            const store = await start('auth.pdf');
            await vi.advanceTimersByTimeAsync(60_000);

            const file = fileOf(store, 'auth.pdf');
            expect(file.status).toBe('expired');
            expect(file.errorCode).toBe('authExpired');
            expect(initUpload).toHaveBeenCalledTimes(1);
        });

        it('an unknown status is the only generic failure, and it carries the status code', async () => {
            initUpload
                .mockReset()
                .mockRejectedValue(new UploadHttpError(418, null));

            const store = await start('odd.pdf');
            await vi.advanceTimersByTimeAsync(60_000);

            const file = fileOf(store, 'odd.pdf');
            expect(file.status).toBe('failed');
            expect(file.errorCode).toBe('serverError');
            expect(file.errorStatus).toBe(418);
            expect(initUpload).toHaveBeenCalledTimes(1);
        });

        it('429 on a chunk is retried automatically and the file never fails', async () => {
            const store = await start('chunky.pdf', 8);

            gates.shift()!.reject(new UploadHttpError(429, null, 3));
            await vi.advanceTimersByTimeAsync(10);
            expect(fileOf(store, 'chunky.pdf').notice).toBe('serverBusy');
            expect(fileOf(store, 'chunky.pdf').status).toBe('uploading');

            // Retry-After: 3 s, honoured.
            await vi.advanceTimersByTimeAsync(3_500);

            while (gates.length > 0) {
                gates.shift()!.resolve();
                await vi.advanceTimersByTimeAsync(10);
            }

            await vi.advanceTimersByTimeAsync(100);
            expect(fileOf(store, 'chunky.pdf').status).toBe('completed');
            expect(fileOf(store, 'chunky.pdf').notice).toBeNull();
            expect(initUpload).toHaveBeenCalledTimes(1);
        });

        it('503 on a chunk is retried with backoff, not shown as a failure', async () => {
            const store = await start('flaky.pdf');

            for (let attempt = 0; attempt < 5; attempt++) {
                gates.shift()!.reject(new UploadHttpError(503, null));
                await vi.advanceTimersByTimeAsync(40_000);
                expect(fileOf(store, 'flaky.pdf').status).toBe('uploading');
            }

            gates.shift()!.resolve();
            await vi.advanceTimersByTimeAsync(100);

            expect(fileOf(store, 'flaky.pdf').status).toBe('completed');
            expect(initUpload).toHaveBeenCalledTimes(1);
        });

        it('503 on complete is retried by itself, then the file completes', async () => {
            completeUpload
                .mockReset()
                .mockRejectedValueOnce(new UploadHttpError(503, null))
                .mockResolvedValue({ uuid: 'media', status: 'uploading' });

            const store = await start('finish.pdf');
            gates.shift()!.resolve();
            await vi.advanceTimersByTimeAsync(100);

            expect(fileOf(store, 'finish.pdf').notice).toBe('temporaryServer');

            await vi.advanceTimersByTimeAsync(1_500);
            expect(fileOf(store, 'finish.pdf').status).toBe('completed');
            expect(completeUpload).toHaveBeenCalledTimes(2);
        });
    });

    it('a pending init is dispatched ahead of the next chunk, even with every slot busy', async () => {
        const { useUploadStore } = await import('./uploads');
        const store = useUploadStore();

        // Two large files: 2 + 1 chunks fill the pool of 3 (per-file cap 2).
        store.enqueueFile(makeFile('big.pdf', 400), 1);
        store.enqueueFile(makeFile('big2.pdf', 400), 1);
        await flush();
        expect(inFlightTotal).toBe(3);

        const initsBefore = initUpload.mock.calls.length;
        store.enqueueFile(makeFile('late.pdf', 4), 1);
        await flush();

        // The init did not wait for a chunk to finish: it used the reserved
        // priority slot while all three chunk slots were still busy.
        expect(initUpload.mock.calls.length).toBe(initsBefore + 1);
        expect(inFlightTotal).toBe(3);
        expect(statusOf(store, 'late.pdf')).toBe('uploading');
    });
});
