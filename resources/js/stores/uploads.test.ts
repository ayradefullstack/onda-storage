import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type * as UploadClientModule from '@/lib/uploadClient';
import type {
    ChunkUploadResponse,
    InitUploadResponse,
    WorkerSliceRequest,
} from '@/types/upload';

/**
 * Exercises the client's chunk path end to end — worker slicing, CRC
 * hand-off, and the chunk request the store issues — through the real
 * `stores/uploads.ts` orchestration, without a browser. `@/lib/uploadClient`
 * is mocked (its own request-construction contract is covered by
 * `uploadClient.test.ts`); the global `Worker` is replaced with a
 * controllable fake so behaviour that only a real browser enforces — a
 * `SecurityError` thrown synchronously from `new Worker(...)`, exactly what
 * the cross-origin dev-server incident produced — can be reproduced here.
 *
 * What this does NOT cover: the actual cross-origin restriction itself
 * (Node has no concept of it — see `workers/chunker.worker.test.ts`), and
 * it does not prove the server accepts a chunk (the Pest suite does that).
 * What it does prove: a synchronous worker-construction failure is caught,
 * surfaces as a visible file/error state instead of hanging forever, and is
 * logged with the real error detail rather than discarded — the exact gap
 * that let the original incident go unnoticed for two manual test cycles.
 */

const initUpload = vi.fn();
const uploadChunk = vi.fn();
const completeUpload = vi.fn();

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
    };
});

type FakeWorkerBehavior = 'success' | 'throwOnConstruct' | 'workerError';

let workerBehavior: FakeWorkerBehavior = 'success';

class FakeWorker {
    private listeners: Record<string, ((event: unknown) => void)[]> = {};

    constructor() {
        if (workerBehavior === 'throwOnConstruct') {
            // The exact failure a cross-origin dev-server Worker
            // construction produced in the real incident.
            throw new DOMException(
                "Failed to construct 'Worker': Script cannot be accessed from origin.",
                'SecurityError',
            );
        }
    }

    addEventListener(type: string, cb: (event: unknown) => void): void {
        (this.listeners[type] ??= []).push(cb);
    }

    postMessage(message: WorkerSliceRequest): void {
        void (async () => {
            if (workerBehavior === 'workerError') {
                this.emit('message', {
                    data: {
                        type: 'error',
                        fileId: message.fileId,
                        index: message.index,
                        message: 'Simulated worker slicing failure.',
                    },
                });

                return;
            }

            const buffer = await message.blob.arrayBuffer();
            this.emit('message', {
                data: {
                    type: 'sliced',
                    fileId: message.fileId,
                    index: message.index,
                    buffer,
                    crc32: 'deadbeef',
                },
            });
        })();
    }

    terminate(): void {}

    private emit(type: string, event: unknown): void {
        this.listeners[type]?.forEach((cb) => cb(event));
    }
}

function makePdfFile(sizeBytes = 44): File {
    return new File([new Uint8Array(sizeBytes)], 'test.pdf', {
        type: 'application/pdf',
    });
}

function initResponse(overrides: Partial<InitUploadResponse> = {}) {
    return {
        uuid: 'session-uuid',
        chunk_size: 8388608,
        total_chunks: 1,
        received: [],
        expires_at: '2026-01-01T00:00:00Z',
        ...overrides,
    } satisfies InitUploadResponse;
}

async function flushMicrotasks(times = 10): Promise<void> {
    for (let i = 0; i < times; i++) {
        await Promise.resolve();
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

describe('upload store — chunk path (worker slicing + request hand-off)', () => {
    beforeEach(() => {
        vi.resetModules();
        workerBehavior = 'success';
        initUpload.mockReset();
        uploadChunk.mockReset();
        completeUpload.mockReset();
        vi.stubGlobal('Worker', FakeWorker);
        // The dev-mode path fetches the worker's source before constructing
        // it; a non-empty response is all `resolveDevWorkerBlobUrl` needs.
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

    it('slices the file with the worker and uploads the resulting buffer + CRC', async () => {
        const { useUploadStore } = await import('./uploads');
        initUpload.mockResolvedValue(initResponse());
        uploadChunk.mockResolvedValue({
            index: 0,
            received: 1,
            total: 1,
            bytes: 44,
        } satisfies ChunkUploadResponse);
        completeUpload.mockResolvedValue({
            uuid: 'media-uuid',
            status: 'uploading',
        });

        const store = useUploadStore();
        const result = store.enqueueFile(makePdfFile(), 70);
        expect(result.ok).toBe(true);

        await flushMicrotasks();

        expect(uploadChunk).toHaveBeenCalledTimes(1);
        const [sessionUuid, index, buffer, crc32] = uploadChunk.mock
            .calls[0] as [string, number, ArrayBuffer, string];
        expect(sessionUuid).toBe('session-uuid');
        expect(index).toBe(0);
        expect(buffer.byteLength).toBe(44);
        expect(crc32).toBe('deadbeef');

        const file = store.files.value.find((f) => f.filename === 'test.pdf');
        expect(file?.status).toBe('completed');
    });

    it('surfaces a synchronous Worker-construction failure as a visible, logged error instead of an unexplained hang', async () => {
        workerBehavior = 'throwOnConstruct';
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});

        const { useUploadStore } = await import('./uploads');
        initUpload.mockResolvedValue(initResponse());

        const store = useUploadStore();
        store.enqueueFile(makePdfFile(), 70);

        await flushMicrotasks();

        expect(uploadChunk).not.toHaveBeenCalled();

        const file = store.files.value.find((f) => f.filename === 'test.pdf');
        expect(file?.status).toBe('failed');
        expect(file?.errorCode).toBe('unknown');
        expect(file?.errorChunkIndex).toBe(0);

        // The regression this guards against: the real error must reach a
        // developer, not be discarded behind the generic 'unknown' code.
        const logged = consoleError.mock.calls
            .map((call) => call.join(' '))
            .join('\n');
        expect(logged).toContain('SecurityError');
        expect(logged).toContain('session-uuid');
    });

    it('carries the requirement slot into init and into the resume record', async () => {
        const storage = new Map<string, string>();
        vi.stubGlobal('sessionStorage', {
            getItem: (key: string) => storage.get(key) ?? null,
            setItem: (key: string, value: string) => storage.set(key, value),
            removeItem: (key: string) => storage.delete(key),
        });

        const { useUploadStore } = await import('./uploads');
        initUpload.mockResolvedValue(initResponse());
        // Never resolves: the upload stays in flight, so its record persists.
        uploadChunk.mockReturnValue(new Promise(() => {}));

        const store = useUploadStore();
        const result = store.enqueueFile(makePdfFile(), 70, {
            id: 42,
            extensions: ['pdf', 'jpg'],
            maxSizeBytes: null,
        });
        expect(result.ok).toBe(true);

        await flushMicrotasks();

        expect(initUpload).toHaveBeenCalledTimes(1);
        expect(
            (initUpload.mock.calls[0] as [Record<string, unknown>])[0],
        ).toMatchObject({ oeuvre_id: 70, college_oeuvre_file_id: 42 });

        const persisted = Object.values(
            JSON.parse(storage.get('onda.uploads.v1') ?? '{}') as Record<
                string,
                { requirementId?: number | null }
            >,
        );
        expect(persisted).toHaveLength(1);
        expect(persisted[0].requirementId).toBe(42);

        // A page refresh: a fresh module restores the entry into its slot.
        vi.resetModules();
        const reloaded = await import('./uploads');
        const restored = reloaded
            .useUploadStore()
            .files.value.find((f) => f.filename === 'test.pdf');

        expect(restored?.requirementId).toBe(42);
        expect(restored?.needsFileReselect).toBe(true);
    });

    it('validates against the slot, case-insensitively, before any request', async () => {
        const { useUploadStore } = await import('./uploads');
        initUpload.mockReturnValue(new Promise(() => {}));
        const store = useUploadStore();
        const slot = { id: 7, extensions: ['mp3'], maxSizeBytes: null };

        expect(store.enqueueFile(makePdfFile(), 70, slot)).toEqual({
            ok: false,
            reason: 'extension',
        });

        const upper = new File([new Uint8Array(4)], 'MASTER.MP3');
        expect(store.enqueueFile(upper, 70, slot).ok).toBe(true);

        await flushMicrotasks();

        expect(initUpload).toHaveBeenCalledTimes(1);
    });

    it('a worker-reported slice error surfaces as workerError, not unknown', async () => {
        workerBehavior = 'workerError';
        const { useUploadStore } = await import('./uploads');
        initUpload.mockResolvedValue(initResponse());

        const store = useUploadStore();
        store.enqueueFile(makePdfFile(), 70);

        await flushMicrotasks();

        expect(uploadChunk).not.toHaveBeenCalled();

        const file = store.files.value.find((f) => f.filename === 'test.pdf');
        expect(file?.status).toBe('failed');
        expect(file?.errorCode).toBe('workerError');
    });
});
