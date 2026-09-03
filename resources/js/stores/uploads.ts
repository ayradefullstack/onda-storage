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
import {
    abortUploadSession,
    completeUpload,
    fetchUploadStatus,
    initUpload,
    UploadHttpError,
    uploadChunk,
} from '@/lib/uploadClient';
import { extensionOf, validateFile } from '@/lib/uploadValidation';
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

function sliceAndHash(
    fileId: string,
    index: number,
    blob: Blob,
): Promise<WorkerResponse> {
    return new Promise((resolve, reject) => {
        const worker = new Worker(
            new URL('../workers/chunker.worker.ts', import.meta.url),
            { type: 'module' },
        );

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

function markExpired(fileState: UploadFileState): void {
    fileState.status = 'expired';
    fileState.errorCode = null;
    fileState.errorChunkIndex = null;
    clearPersisted(fileState.id);
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

                    throw error;
                }

                if (error.status === 413) {
                    chunk.status = 'failed';
                    markQuotaExceeded(fileState, error.body);

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
        fileState.status = 'failed';

        if (error instanceof UploadHttpError && error.status === 409) {
            setError(fileState, 'missingChunks');

            return;
        }

        setError(
            fileState,
            error instanceof UploadHttpError ? 'serverError' : 'network',
        );
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
            } catch {
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
        } else {
            fileState.status = 'failed';
            setError(
                fileState,
                error instanceof UploadHttpError ? 'serverError' : 'network',
            );
        }

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

    const id = crypto.randomUUID();
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
