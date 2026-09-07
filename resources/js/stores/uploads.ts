/**
 * App-level upload queue — a plain module-scope reactive singleton, NOT a
 * page-level composable's local state. This is the one design decision the
 * whole phase hinges on: because it lives in module scope rather than in a
 * component's setup(), an Inertia navigation (which only swaps the current
 * page component) never touches it — the fetch calls, retry timers, and
 * worker slicing already in flight simply keep running underneath whatever
 * page is currently mounted. `useUploadQueue.ts` is the component-facing
 * read/action surface over this same state; UI components should generally
 * go through that rather than importing this module directly.
 *
 * Built on Vue's own `reactive`/`computed` rather than Pinia — Pinia isn't
 * a project dependency and this phase's constraints are "existing
 * primitives + Tailwind only, no new packages." The shape (state + actions
 * behind a `useX()` accessor) is deliberately Pinia-like so swapping to a
 * real Pinia store later, if the project ever adds it, is a mechanical
 * change, not a redesign.
 */
import { computed, reactive } from 'vue';
import { generateClientId } from '@/lib/id';
import {
    abortUploadSession,
    completeUpload,
    fetchUploadStatus,
    initUpload,
    UploadHttpError,
    uploadChunk,
} from '@/lib/uploadClient';
import { extensionOf, validateFile } from '@/lib/uploadValidation';
// `?worker&inline` (not `new Worker(new URL(...), { type: 'module' })`):
// this app's outer HTML is served by Laravel, not by Vite itself, so in dev
// the page's own origin and the Vite dev server's asset origin differ —
// browsers refuse to construct a Worker from a cross-origin script URL
// (unlike a plain `<script type=module>` import, which Vite's permissive
// dev-server CORS headers do allow). `&inline` bundles the worker's source
// into this module and constructs it from a `blob:` URL at runtime — always
// same-origin, in both dev and the production build, so this isn't a
// dev-only workaround. If a CSP is ever added, `worker-src` must permit
// `blob:` (not `data:`) or this breaks again.
import type {
    ChunkUploadResponse,
    InitUploadResponse,
    PersistedUpload,
    QuotaExceededResponse,
    UploadChunk,
    UploadErrorCode,
    UploadFileState,
    WorkerResponse,
    WorkerSliceRequest,
} from '@/types/upload';
import ChunkerWorker from '../workers/chunker.worker.ts?worker&inline';

const SESSION_STORAGE_KEY = 'onda.uploads.v1';
const CHUNK_CONCURRENCY_PER_FILE = 3;
const MAX_CHUNK_ATTEMPTS = 4;
const BACKOFF_MS = [1000, 2000, 4000, 8000];

interface StoreState {
    files: Map<string, UploadFileState>;
    order: string[];
    maxConcurrentFiles: number;
}

const state = reactive<StoreState>({
    files: new Map(),
    order: [],
    maxConcurrentFiles: 1,
});

const abortControllers = new Map<string, AbortController>();

interface ProgressSample {
    lastBytes: number;
    lastTime: number;
    emaSpeed: number;
}

const progressSamples = new Map<string, ProgressSample>();

// --- chunk slicing (one Worker per chunk, terminated after use) --------

/**
 * `?worker&inline` genuinely inlines the worker as a base64 blob at build
 * time — production uses it unchanged, and it works. In dev, though, Vite
 * still constructs the Worker directly against the dev-server URL despite
 * the `&inline` flag; since the app's origin and the dev server's origin
 * differ (e.g. `onda-storage.test` vs `http://[::1]:5173`), the browser
 * refuses to construct a Worker from that cross-origin URL at all — this
 * is a hard restriction on Worker construction specifically, not a CORS
 * problem a permissive header can fix (confirmed via the actual browser
 * exception: `SecurityError: Failed to construct 'Worker': Script at
 * '...' cannot be accessed from origin '...'`). Every chunk failed
 * silently with `errorCode: 'unknown'` because of this — chunk 0 never
 * even reached the network.
 *
 * The workaround: fetch the worker's dev-transformed source ourselves
 * (a plain cross-origin `fetch` is fine — only Worker *construction* is
 * restricted) and construct the Worker from a `Blob` we create, which is
 * always same-origin to the current page regardless of where its content
 * came from. This only works because the worker file has zero imports to
 * resolve (see its own top comment) — the fetched text is already
 * self-contained. The blob URL is cached and reused for every chunk of
 * every file; only the first chunk pays the extra fetch.
 */
let devWorkerBlobUrl: Promise<string> | null = null;

async function resolveDevWorkerBlobUrl(): Promise<string> {
    devWorkerBlobUrl ??= (async () => {
        const workerModuleUrl = new URL(
            '../workers/chunker.worker.ts',
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

async function sliceAndHash(
    fileId: string,
    index: number,
    blob: Blob,
): Promise<WorkerResponse> {
    const worker = await createChunkerWorker();

    return new Promise((resolve, reject) => {
        worker.addEventListener(
            'message',
            (event: MessageEvent<WorkerResponse>) => {
                worker.terminate();
                resolve(event.data);
            },
        );

        worker.addEventListener('error', (event: ErrorEvent) => {
            worker.terminate();
            reject(
                new Error(
                    event.message || 'Worker error while slicing a chunk.',
                ),
            );
        });

        const message: WorkerSliceRequest = {
            type: 'slice',
            fileId,
            index,
            blob,
        };
        worker.postMessage(message);
    });
}

function sleep(ms: number, signal: AbortSignal): Promise<void> {
    return new Promise((resolve, reject) => {
        if (signal.aborted) {
            reject(new DOMException('Aborted', 'AbortError'));

            return;
        }

        const timer = setTimeout(resolve, ms);
        signal.addEventListener(
            'abort',
            () => {
                clearTimeout(timer);
                reject(new DOMException('Aborted', 'AbortError'));
            },
            { once: true },
        );
    });
}

function isQuotaExceededBody(body: unknown): body is QuotaExceededResponse {
    return (
        typeof body === 'object' && body !== null && 'remaining_bytes' in body
    );
}

function recordProgress(
    fileState: UploadFileState,
    response: ChunkUploadResponse,
): void {
    const now = performance.now();
    const sample = progressSamples.get(fileState.id) ?? {
        lastBytes: 0,
        lastTime: now,
        emaSpeed: 0,
    };

    const deltaBytes = response.bytes - sample.lastBytes;
    const deltaSeconds = Math.max(0.001, (now - sample.lastTime) / 1000);
    const instantSpeed = Math.max(0, deltaBytes / deltaSeconds);
    const alpha = 0.3;

    sample.emaSpeed =
        sample.emaSpeed === 0
            ? instantSpeed
            : alpha * instantSpeed + (1 - alpha) * sample.emaSpeed;
    sample.lastBytes = response.bytes;
    sample.lastTime = now;
    progressSamples.set(fileState.id, sample);

    fileState.bytesUploaded = response.bytes;
    fileState.speedBps = sample.emaSpeed;

    const remaining = fileState.size - response.bytes;
    fileState.etaSeconds =
        sample.emaSpeed > 0 ? remaining / sample.emaSpeed : null;
}

function setError(
    fileState: UploadFileState,
    code: UploadErrorCode,
    chunkIndex: number | null = null,
): void {
    fileState.errorCode = code;
    fileState.errorChunkIndex = chunkIndex;
}

function markExpired(
    fileState: UploadFileState,
    reason: 'sessionExpired' | 'authExpired' = 'sessionExpired',
): void {
    fileState.status = 'expired';
    fileState.errorCode = reason === 'authExpired' ? 'authExpired' : null;
    fileState.errorChunkIndex = null;
    clearPersisted(fileState.id);
}

/**
 * The one place an upload failure's real cause is guaranteed to reach a
 * developer. `UploadErrorCode` is a fixed, translatable enum shown to the
 * user — it was never meant to (and can't) carry a stack trace, so
 * discarding the underlying error after mapping it left a `'unknown'`
 * result with nothing behind it to debug (this masked a real bug for two
 * manual test cycles: a cross-origin dev-server Worker construction
 * failure that killed every chunk before any network request was made).
 */
function logUploadFailure(
    stage: 'init' | 'chunk' | 'complete',
    fileState: UploadFileState,
    error: unknown,
    chunkIndex: number | null = null,
): void {
    const detail =
        error instanceof UploadHttpError
            ? `httpStatus=${error.status}`
            : error instanceof Error
              ? `name=${error.name} message=${error.message}`
              : `value=${String(error)}`;

    console.error(
        `[upload] ${stage} failed session=${fileState.sessionUuid ?? 'none'} file=${fileState.filename}${chunkIndex === null ? '' : ` chunk=${chunkIndex}`} ${detail}`,
        error instanceof Error ? error.stack : error,
    );
}

function markQuotaExceeded(fileState: UploadFileState, body: unknown): void {
    fileState.status = 'quota_exceeded';
    fileState.remainingQuotaBytes = isQuotaExceededBody(body)
        ? body.remaining_bytes
        : null;
    clearPersisted(fileState.id);
}

// --- sessionStorage persistence (resume-after-refresh) ------------------

function readPersistedMap(): Record<string, PersistedUpload> {
    try {
        const raw = sessionStorage.getItem(SESSION_STORAGE_KEY);

        return raw ? (JSON.parse(raw) as Record<string, PersistedUpload>) : {};
    } catch {
        return {};
    }
}

function writePersistedMap(map: Record<string, PersistedUpload>): void {
    try {
        sessionStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(map));
    } catch {
        // Private-mode / quota-exhausted sessionStorage degrades to
        // "no resume offer after a refresh" — not fatal to the upload itself.
    }
}

function persist(fileState: UploadFileState): void {
    if (!fileState.sessionUuid) {
        return;
    }

    const map = readPersistedMap();
    map[fileState.id] = {
        id: fileState.id,
        workId: fileState.workId,
        sessionUuid: fileState.sessionUuid,
        filename: fileState.filename,
        size: fileState.size,
        mime: fileState.mime,
        extension: fileState.extension,
        chunkSize: fileState.chunkSize,
        totalChunks: fileState.totalChunks,
        expiresAt: fileState.expiresAt ?? '',
    };
    writePersistedMap(map);
}

function clearPersisted(fileId: string): void {
    const map = readPersistedMap();
    delete map[fileId];
    writePersistedMap(map);
}

function buildChunks(
    size: number,
    chunkSize: number,
    totalChunks: number,
    receivedIndices: Iterable<number>,
): UploadChunk[] {
    const received = new Set(receivedIndices);
    const chunks: UploadChunk[] = [];

    for (let index = 0; index < totalChunks; index++) {
        const offset = index * chunkSize;
        const isFinal = index === totalChunks - 1;
        const length = isFinal ? size - offset : chunkSize;

        chunks.push({
            index,
            offset,
            length,
            status: received.has(index) ? 'done' : 'pending',
            attempts: 0,
        });
    }

    return chunks;
}

let restored = false;

function restorePersistedUploads(): void {
    if (restored) {
        return;
    }

    restored = true;

    for (const persistedUpload of Object.values(readPersistedMap())) {
        if (state.files.has(persistedUpload.id)) {
            continue;
        }

        const fileState: UploadFileState = {
            id: persistedUpload.id,
            workId: persistedUpload.workId,
            file: null,
            filename: persistedUpload.filename,
            size: persistedUpload.size,
            mime: persistedUpload.mime,
            extension: persistedUpload.extension,
            sessionUuid: persistedUpload.sessionUuid,
            chunkSize: persistedUpload.chunkSize,
            totalChunks: persistedUpload.totalChunks,
            chunks: buildChunks(
                persistedUpload.size,
                persistedUpload.chunkSize,
                persistedUpload.totalChunks,
                [],
            ),
            status: 'paused',
            errorCode: null,
            errorChunkIndex: null,
            remainingQuotaBytes: null,
            bytesUploaded: 0,
            speedBps: 0,
            etaSeconds: null,
            expiresAt: persistedUpload.expiresAt,
            mediaFileUuid: null,
            needsFileReselect: true,
        };

        state.files.set(persistedUpload.id, fileState);
        state.order.push(persistedUpload.id);
    }
}

// --- per-chunk upload with retry -----------------------------------------

async function uploadChunkWithRetry(
    fileState: UploadFileState,
    chunk: UploadChunk,
    signal: AbortSignal,
): Promise<void> {
    const blob = fileState.file!.slice(
        chunk.offset,
        chunk.offset + chunk.length,
    );
    chunk.status = 'slicing';

    const sliceResult = await sliceAndHash(fileState.id, chunk.index, blob);

    if (signal.aborted) {
        chunk.status = 'pending';

        throw new DOMException('Aborted', 'AbortError');
    }

    if (sliceResult.type === 'error') {
        chunk.status = 'failed';
        setError(fileState, 'workerError', chunk.index);
        logUploadFailure(
            'chunk',
            fileState,
            new Error(sliceResult.message),
            chunk.index,
        );

        throw new Error(sliceResult.message);
    }

    chunk.status = 'uploading';

    let attempt = 0;
    let usedImmediateCrcRetry = false;

    for (;;) {
        attempt++;
        chunk.attempts = attempt;

        try {
            const response = await uploadChunk(
                fileState.sessionUuid!,
                chunk.index,
                sliceResult.buffer,
                sliceResult.crc32,
                signal,
            );
            chunk.status = 'done';
            recordProgress(fileState, response);

            return;
        } catch (error) {
            if (signal.aborted) {
                chunk.status = 'pending';

                throw error;
            }

            if (error instanceof UploadHttpError) {
                if (error.status === 410) {
                    chunk.status = 'failed';
                    markExpired(fileState);
                    logUploadFailure('chunk', fileState, error, chunk.index);

                    throw error;
                }

                if (error.status === 413) {
                    chunk.status = 'failed';
                    markQuotaExceeded(fileState, error.body);
                    logUploadFailure('chunk', fileState, error, chunk.index);

                    throw error;
                }

                // 419: the browser session (login), not the upload session,
                // expired — a different problem from 410 with a different
                // fix (log in again, not just reselect the file), so it
                // gets its own errorCode even though both land on the same
                // 'expired' file status.
                if (error.status === 419) {
                    chunk.status = 'failed';
                    markExpired(fileState, 'authExpired');
                    logUploadFailure('chunk', fileState, error, chunk.index);

                    throw error;
                }

                if (error.status === 422 && !usedImmediateCrcRetry) {
                    // One immediate retry, no backoff — a CRC mismatch is
                    // transit corruption, not a reason to wait.
                    usedImmediateCrcRetry = true;
                    continue;
                }
            }

            if (attempt >= MAX_CHUNK_ATTEMPTS) {
                chunk.status = 'failed';
                fileState.status = 'failed';
                const code: UploadErrorCode =
                    error instanceof UploadHttpError && error.status === 422
                        ? 'crcMismatch'
                        : 'network';
                setError(fileState, code, chunk.index);
                logUploadFailure('chunk', fileState, error, chunk.index);

                throw error;
            }

            await sleep(
                BACKOFF_MS[
                    Math.min(attempt - 1, BACKOFF_MS.length - 1)
                ] as number,
                signal,
            );
        }
    }
}

async function finalizeFile(
    fileState: UploadFileState,
    signal: AbortSignal,
): Promise<void> {
    fileState.status = 'completing';

    try {
        const response = await completeUpload(fileState.sessionUuid!, signal);
        fileState.status = 'completed';
        fileState.mediaFileUuid = response.uuid;
        clearPersisted(fileState.id);
    } catch (error) {
        if (error instanceof UploadHttpError && error.status === 419) {
            markExpired(fileState, 'authExpired');
            logUploadFailure('complete', fileState, error);

            return;
        }

        fileState.status = 'failed';

        if (error instanceof UploadHttpError && error.status === 409) {
            setError(fileState, 'missingChunks');
            logUploadFailure('complete', fileState, error);

            return;
        }

        setError(
            fileState,
            error instanceof UploadHttpError ? 'serverError' : 'network',
        );
        logUploadFailure('complete', fileState, error);
    }
}

async function runFileUploadLoop(fileState: UploadFileState): Promise<void> {
    const controller = new AbortController();
    abortControllers.set(fileState.id, controller);
    fileState.status = 'uploading';
    fileState.errorCode = null;
    fileState.errorChunkIndex = null;

    async function slot(): Promise<void> {
        for (;;) {
            if (controller.signal.aborted) {
                return;
            }

            const next = fileState.chunks.find((c) => c.status === 'pending');

            if (!next) {
                return;
            }

            next.status = 'slicing'; // claimed synchronously — no other slot can race this

            try {
                await uploadChunkWithRetry(fileState, next, controller.signal);
            } catch (error) {
                // uploadChunkWithRetry already sets status/errorCode for
                // every failure it anticipates (max retry attempts, 410,
                // 413, a worker-reported slice error). This is the
                // catch-all for everything else — e.g. sliceAndHash's
                // `new Worker(...)` throwing before any of that logic runs
                // (the actual cause of a real bug this masked: a
                // cross-origin dev-server Worker construction failure left
                // the file frozen at 'uploading' forever with no error
                // shown). A deliberate pause/cancel also lands here as an
                // AbortError — that's not a failure, so it's excluded.
                if (
                    error instanceof DOMException &&
                    error.name === 'AbortError'
                ) {
                    return;
                }

                if (fileState.status === 'uploading') {
                    fileState.status = 'failed';
                    setError(fileState, 'unknown', next.index);
                    logUploadFailure('chunk', fileState, error, next.index);
                }

                return;
            }
        }
    }

    await Promise.all(
        Array.from({ length: CHUNK_CONCURRENCY_PER_FILE }, () => slot()),
    );
    abortControllers.delete(fileState.id);

    if (fileState.status !== 'uploading') {
        return; // failed / expired / quota_exceeded / paused already handled by a slot
    }

    if (!fileState.chunks.every((c) => c.status === 'done')) {
        fileState.status = 'paused';

        return;
    }

    await finalizeFile(fileState, controller.signal);
    pumpQueue();
}

// --- init + queue scheduling ---------------------------------------------

function applyInitResponse(
    fileState: UploadFileState,
    response: InitUploadResponse,
): void {
    fileState.sessionUuid = response.uuid;
    fileState.chunkSize = response.chunk_size;
    fileState.totalChunks = response.total_chunks;
    fileState.expiresAt = response.expires_at;
    fileState.chunks = buildChunks(
        fileState.size,
        response.chunk_size,
        response.total_chunks,
        response.received,
    );
}

async function startFile(fileState: UploadFileState): Promise<void> {
    fileState.status = 'initializing';

    try {
        const response = await initUpload({
            work_id: fileState.workId,
            filename: fileState.filename,
            size_bytes: fileState.size,
            mime: fileState.mime,
        });
        applyInitResponse(fileState, response);
        persist(fileState);
        await runFileUploadLoop(fileState);
    } catch (error) {
        if (error instanceof UploadHttpError && error.status === 413) {
            markQuotaExceeded(fileState, error.body);
        } else if (error instanceof UploadHttpError && error.status === 419) {
            markExpired(fileState, 'authExpired');
        } else {
            fileState.status = 'failed';
            setError(
                fileState,
                error instanceof UploadHttpError ? 'serverError' : 'network',
            );
        }

        logUploadFailure('init', fileState, error);
        pumpQueue();
    }
}

function activeFileCount(): number {
    return state.order.reduce((count, id) => {
        const fileState = state.files.get(id);

        return fileState &&
            (fileState.status === 'initializing' ||
                fileState.status === 'uploading' ||
                fileState.status === 'completing')
            ? count + 1
            : count;
    }, 0);
}

function pumpQueue(): void {
    let slotsAvailable = state.maxConcurrentFiles - activeFileCount();

    if (slotsAvailable <= 0) {
        return;
    }

    for (const id of state.order) {
        if (slotsAvailable <= 0) {
            break;
        }

        const fileState = state.files.get(id);

        if (fileState?.status === 'queued') {
            slotsAvailable--;
            void startFile(fileState);
        }
    }
}

// --- public actions --------------------------------------------------------

function enqueueFile(
    file: File,
    workId: number,
): { ok: true; id: string } | { ok: false; reason: 'extension' | 'size' } {
    const validation = validateFile(file);

    if (!validation.ok) {
        return { ok: false, reason: validation.reason };
    }

    const id = generateClientId();

    const fileState: UploadFileState = {
        id,
        workId,
        file,
        filename: file.name,
        size: file.size,
        mime: file.type || 'application/octet-stream',
        extension: extensionOf(file.name),
        sessionUuid: null,
        chunkSize: 0,
        totalChunks: 0,
        chunks: [],
        status: 'queued',
        errorCode: null,
        errorChunkIndex: null,
        remainingQuotaBytes: null,
        bytesUploaded: 0,
        speedBps: 0,
        etaSeconds: null,
        expiresAt: null,
        mediaFileUuid: null,
        needsFileReselect: false,
    };

    state.files.set(id, fileState);
    state.order.push(id);
    pumpQueue();

    return { ok: true, id };
}

function pauseFile(fileId: string): void {
    const fileState = state.files.get(fileId);

    if (!fileState) {
        return;
    }

    abortControllers.get(fileId)?.abort();

    if (
        fileState.status === 'uploading' ||
        fileState.status === 'initializing'
    ) {
        fileState.status = 'paused';
    }
}

function resumeFile(fileId: string): void {
    const fileState = state.files.get(fileId);

    if (!fileState?.file || !fileState.sessionUuid) {
        return;
    }

    if (fileState.status !== 'paused' && fileState.status !== 'failed') {
        return;
    }

    fileState.chunks.forEach((c) => {
        if (c.status !== 'done') {
            c.status = 'pending';
        }
    });
    fileState.errorCode = null;
    fileState.errorChunkIndex = null;
    void runFileUploadLoop(fileState);
}

async function cancelFile(fileId: string): Promise<void> {
    const fileState = state.files.get(fileId);

    if (!fileState) {
        return;
    }

    abortControllers.get(fileId)?.abort();

    if (fileState.sessionUuid && fileState.status !== 'completed') {
        try {
            await abortUploadSession(fileState.sessionUuid);
        } catch {
            // Best-effort — an orphaned .part file is reclaimed by the P7
            // retention job regardless of whether this call succeeds.
        }
    }

    clearPersisted(fileId);
    progressSamples.delete(fileId);
    state.files.delete(fileId);
    state.order = state.order.filter((id) => id !== fileId);
}

/**
 * Re-attaches a File selected after a page refresh (a File handle cannot
 * survive a reload) to a persisted, paused upload. Validates size before
 * resuming and re-checks the server's mask, since chunks may have arrived —
 * or the session may have expired — since the last visit.
 */
function resumeWithReselectedFile(
    fileId: string,
    file: File,
): { ok: true } | { ok: false; reason: 'size_mismatch' | 'not_found' } {
    const fileState = state.files.get(fileId);

    if (!fileState) {
        return { ok: false, reason: 'not_found' };
    }

    if (file.size !== fileState.size) {
        return { ok: false, reason: 'size_mismatch' };
    }

    fileState.file = file;
    fileState.needsFileReselect = false;
    void reconcileAndResume(fileState);

    return { ok: true };
}

async function reconcileAndResume(fileState: UploadFileState): Promise<void> {
    try {
        const status = await fetchUploadStatus(fileState.sessionUuid!);

        if (status.status !== 'uploading') {
            markExpired(fileState);

            return;
        }

        const received = new Set(status.received);
        fileState.chunks.forEach((c) => {
            c.status = received.has(c.index) ? 'done' : 'pending';
        });
        fileState.bytesUploaded = status.received_bytes;
        fileState.expiresAt = status.expires_at;
        await runFileUploadLoop(fileState);
    } catch (error) {
        if (error instanceof UploadHttpError && error.status === 410) {
            markExpired(fileState);

            return;
        }

        fileState.status = 'failed';
        setError(
            fileState,
            error instanceof UploadHttpError ? 'serverError' : 'network',
        );
    }
}

// --- reactive read surface --------------------------------------------------

const filesInOrder = computed<UploadFileState[]>(() =>
    state.order
        .map((id) => state.files.get(id))
        .filter((f): f is UploadFileState => f !== undefined),
);

const hasActiveUploads = computed(() =>
    filesInOrder.value.some(
        (f) =>
            f.status === 'uploading' ||
            f.status === 'initializing' ||
            f.status === 'completing',
    ),
);

export function useUploadStore() {
    restorePersistedUploads();

    return {
        files: filesInOrder,
        hasActiveUploads,
        maxConcurrentFiles: computed({
            get: () => state.maxConcurrentFiles,
            set: (value: number) => {
                state.maxConcurrentFiles = Math.max(1, Math.floor(value));
                pumpQueue();
            },
        }),
        enqueueFile,
        pauseFile,
        resumeFile,
        cancelFile,
        resumeWithReselectedFile,
    };
}
