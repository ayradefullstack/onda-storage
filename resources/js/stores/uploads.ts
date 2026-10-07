/**
 * App-level upload store and scheduler host — a plain module-scope reactive
 * singleton, NOT a page-level composable's local state. Every file is
 * independent state keyed by its own id (no "current upload"); the shared
 * fair scheduler in `lib/upload/scheduler.ts` decides whose chunk goes next. This is the one design decision the
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
import { usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import { generateClientId } from '@/lib/id';
import {
    classifyFailure,
    isTransient,
    MAX_TRANSIENT_ATTEMPTS,
    transientDelayMs,
} from '@/lib/upload/failure';
import type { UploadFailure } from '@/lib/upload/failure';
import { createScheduler, DEFAULT_LIMITS } from '@/lib/upload/scheduler';
import type { SchedulerLimits } from '@/lib/upload/scheduler';
import { createWorkerPool } from '@/lib/upload/workerPool';
import {
    abortUploadSession,
    completeUpload,
    fetchUploadStatus,
    initUpload,
    UploadHttpError,
    uploadChunk,
} from '@/lib/uploadClient';
import {
    extensionOf,
    validateFile,
    validateFileForRequirement,
} from '@/lib/uploadValidation';
import type {
    ChunkUploadResponse,
    InitUploadResponse,
    PersistedUpload,
    UploadChunk,
    UploadErrorCode,
    UploadFileState,
    UploadRequirement,
} from '@/types/upload';

const SESSION_STORAGE_KEY = 'onda.uploads.v1';
const MAX_CHUNK_ATTEMPTS = 4;
const BACKOFF_MS = [1000, 2000, 4000, 8000];

interface StoreState {
    files: Map<string, UploadFileState>;
    order: string[];
}

const state = reactive<StoreState>({
    files: new Map(),
    order: [],
});

const abortControllers = new Map<string, AbortController>();

/**
 * Limits come from the server (shared Inertia prop `upload`, backed by
 * config/vault.php) so production can be tuned to the host's PHP process
 * limit without a rebuild. Read at every scheduling turn, so a changed
 * prop applies without re-registering anything.
 */
function readLimits(): SchedulerLimits {
    try {
        const shared = usePage().props.upload as
            Partial<SchedulerLimits> | undefined;

        return {
            maxInFlight: shared?.maxInFlight ?? DEFAULT_LIMITS.maxInFlight,
            perFileInFlight:
                shared?.perFileInFlight ?? DEFAULT_LIMITS.perFileInFlight,
        };
    } catch {
        return DEFAULT_LIMITS;
    }
}

const scheduler = createScheduler(readLimits);
const workerPool = createWorkerPool();

interface ProgressSample {
    lastBytes: number;
    lastTime: number;
    emaSpeed: number;
}

const progressSamples = new Map<string, ProgressSample>();

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

    // Concurrent chunks can answer out of order; never move backwards.
    fileState.bytesUploaded = Math.max(fileState.bytesUploaded, response.bytes);
    fileState.speedBps = sample.emaSpeed;

    const remaining = fileState.size - fileState.bytesUploaded;
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

function markQuotaExceeded(
    fileState: UploadFileState,
    failure: Extract<UploadFailure, { kind: 'quota' }>,
): void {
    fileState.status = 'quota_exceeded';
    fileState.remainingQuotaBytes = failure.remainingBytes;
    fileState.neededQuotaBytes = failure.neededBytes ?? fileState.size;
    clearPersisted(fileState.id);
}

/**
 * The terminal outcome of a classified failure: each kind has its own state
 * and message, and none of them touches another file. Transient kinds only
 * get here once their automatic retries are exhausted.
 */
function failWith(
    fileState: UploadFileState,
    failure: UploadFailure,
    chunkIndex: number | null = null,
): void {
    fileState.notice = null;

    switch (failure.kind) {
        case 'quota':
            markQuotaExceeded(fileState, failure);

            return;
        case 'authExpired':
            markExpired(fileState, 'authExpired');

            return;
        case 'sessionGone':
            markExpired(fileState);

            return;
        case 'validation':
            fileState.status = 'failed';
            fileState.errorMessage = failure.message;
            setError(fileState, 'validation', chunkIndex);

            return;
        case 'busy':
            fileState.status = 'failed';
            setError(fileState, 'serverBusy', chunkIndex);

            return;
        case 'temporary':
            fileState.status = 'failed';
            setError(fileState, 'temporaryServer', chunkIndex);

            return;
        case 'unknown':
            fileState.status = 'failed';
            fileState.errorStatus = failure.status;
            setError(fileState, 'serverError', chunkIndex);
    }
}

function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function noticeFor(
    failure: Extract<UploadFailure, { kind: 'busy' | 'temporary' }>,
): 'serverBusy' | 'temporaryServer' {
    return failure.kind === 'busy' ? 'serverBusy' : 'temporaryServer';
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
        oeuvreId: fileState.oeuvreId,
        requirementId: fileState.requirementId,
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
            // A record written before the works → oeuvres rename carries
            // `workId`; without the fallback a tab that was mid-upload at
            // deploy time would restore an entry no page ever matches.
            oeuvreId: persistedUpload.oeuvreId ?? persistedUpload.workId,
            // Restored so a resumed file reappears in its own slot, not
            // slot-less while the server session still carries the slot.
            requirementId: persistedUpload.requirementId ?? null,
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
            neededQuotaBytes: null,
            errorMessage: null,
            errorStatus: null,
            notice: null,
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

// --- per-chunk upload (one attempt per scheduler turn) ---------------------

/** When a chunk that failed may be tried again. Keyed `fileId:index`. */
const retryAt = new Map<string, number>();

function retryKey(fileState: UploadFileState, chunk: UploadChunk): string {
    return `${fileState.id}:${chunk.index}`;
}

function clearRetries(fileState: UploadFileState): void {
    for (const chunk of fileState.chunks) {
        retryAt.delete(retryKey(fileState, chunk));
    }
}

/**
 * Stops a file for good (permanent failure) or for now (pause): it leaves
 * the rotation and its sibling in-flight chunks are cancelled. Nothing here
 * touches any other file.
 */
function deactivate(fileState: UploadFileState): void {
    scheduler.unregister(fileState.id);
    abortControllers.get(fileState.id)?.abort();
    abortControllers.delete(fileState.id);
}

async function uploadOneChunk(
    fileState: UploadFileState,
    chunk: UploadChunk,
    signal: AbortSignal,
): Promise<void> {
    const blob = fileState.file!.slice(
        chunk.offset,
        chunk.offset + chunk.length,
    );
    chunk.status = 'slicing';

    const sliceResult = await workerPool.slice(
        fileState.sessionUuid!,
        chunk.index,
        blob,
        signal,
    );

    if (signal.aborted) {
        chunk.status = 'pending';

        throw new DOMException('Aborted', 'AbortError');
    }

    if (sliceResult.type === 'error') {
        chunk.status = 'failed';
        // Set before throwing so the caller's catch-all does not overwrite
        // this specific code with 'unknown'.
        fileState.status = 'failed';
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

    let usedImmediateCrcRetry = false;

    for (;;) {
        chunk.attempts++;

        try {
            const response = await uploadChunk(
                fileState.sessionUuid!,
                chunk.index,
                sliceResult.buffer,
                sliceResult.crc32,
                signal,
            );
            chunk.status = 'done';
            fileState.notice = null;
            retryAt.delete(retryKey(fileState, chunk));
            recordProgress(fileState, response);

            return;
        } catch (error) {
            if (signal.aborted) {
                chunk.status = 'pending';

                throw error;
            }

            let failure: UploadFailure | null = null;

            if (error instanceof UploadHttpError) {
                failure = classifyFailure(error);

                // Stops for this file alone: its session is gone (410), the
                // login expired (419, not the upload session), or its quota
                // is exhausted (413). No other file is consulted.
                if (
                    failure.kind === 'sessionGone' ||
                    failure.kind === 'authExpired' ||
                    failure.kind === 'quota'
                ) {
                    chunk.status = 'failed';
                    failWith(fileState, failure, chunk.index);
                    logUploadFailure('chunk', fileState, error, chunk.index);

                    throw error;
                }

                if (failure.kind === 'validation' && !usedImmediateCrcRetry) {
                    // One immediate retry, no backoff: a CRC mismatch is
                    // transit corruption, not a reason to wait.
                    usedImmediateCrcRetry = true;
                    continue;
                }
            }

            // 429 / 502 / 503 / 504: the server is busy or briefly down, not
            // the file's fault. Retry by itself, honouring Retry-After, for a
            // much longer run than an unexplained failure gets.
            if (failure !== null && isTransient(failure)) {
                if (chunk.attempts >= MAX_TRANSIENT_ATTEMPTS) {
                    chunk.status = 'failed';
                    failWith(fileState, failure, chunk.index);
                    logUploadFailure('chunk', fileState, error, chunk.index);

                    throw error;
                }

                fileState.notice = noticeFor(failure);
                chunk.status = 'pending';
                const wait = transientDelayMs(failure, chunk.attempts);
                retryAt.set(retryKey(fileState, chunk), Date.now() + wait);
                setTimeout(() => scheduler.pump(), wait + 5);

                return;
            }

            if (chunk.attempts >= MAX_CHUNK_ATTEMPTS) {
                chunk.status = 'failed';

                if (failure !== null && failure.kind === 'validation') {
                    fileState.status = 'failed';
                    setError(fileState, 'crcMismatch', chunk.index);
                } else if (failure !== null) {
                    failWith(fileState, failure, chunk.index);
                } else {
                    fileState.status = 'failed';
                    setError(fileState, 'network', chunk.index);
                }

                logUploadFailure('chunk', fileState, error, chunk.index);

                throw error;
            }

            // Back off WITHOUT holding a pool slot: the chunk goes back to
            // pending with a not-before time, and the scheduler hands the
            // slot to another file in the meantime.
            const delay = BACKOFF_MS[
                Math.min(chunk.attempts - 1, BACKOFF_MS.length - 1)
            ] as number;
            chunk.status = 'pending';
            retryAt.set(retryKey(fileState, chunk), Date.now() + delay);
            setTimeout(() => scheduler.pump(), delay + 5);

            return;
        }
    }
}

async function finalizeFile(fileState: UploadFileState): Promise<void> {
    fileState.status = 'completing';
    scheduler.unregister(fileState.id);
    abortControllers.delete(fileState.id);

    for (let attempt = 1; ; attempt++) {
        try {
            const response = await completeUpload(fileState.sessionUuid!);
            fileState.status = 'completed';
            fileState.notice = null;
            fileState.mediaFileUuid = response.uuid;
            clearPersisted(fileState.id);

            return;
        } catch (error) {
            const failure =
                error instanceof UploadHttpError
                    ? classifyFailure(error)
                    : null;

            if (
                failure !== null &&
                isTransient(failure) &&
                attempt < MAX_TRANSIENT_ATTEMPTS &&
                state.files.has(fileState.id)
            ) {
                fileState.notice = noticeFor(failure);
                await sleep(transientDelayMs(failure, attempt));

                continue;
            }

            if (error instanceof UploadHttpError && error.status === 409) {
                fileState.status = 'failed';
                setError(fileState, 'missingChunks');
            } else if (failure !== null) {
                failWith(fileState, failure);
            } else {
                fileState.status = 'failed';
                setError(fileState, 'network');
            }

            logUploadFailure('complete', fileState, error);

            return;
        }
    }
}

/**
 * The scheduler's unit of work for one claimed chunk. It owns this file's
 * failures: whatever happens, it settles without rejecting, so the pool and
 * every other file carry on.
 */
async function runChunkJob(
    fileState: UploadFileState,
    chunk: UploadChunk,
    controller: AbortController,
): Promise<void> {
    try {
        await uploadOneChunk(fileState, chunk, controller.signal);
    } catch (error) {
        // A deliberate pause/cancel/sibling-failure abort is not a failure.
        if (!(error instanceof DOMException && error.name === 'AbortError')) {
            // uploadOneChunk set status/errorCode for every failure it
            // anticipates; this catch-all covers the rest (e.g. the Worker
            // constructor throwing before any request is made).
            if (fileState.status === 'uploading') {
                fileState.status = 'failed';
                setError(fileState, 'unknown', chunk.index);
                logUploadFailure('chunk', fileState, error, chunk.index);
            }
        }
    }

    if (fileState.status !== 'uploading') {
        // failed / expired / quota_exceeded / paused: leave the rotation.
        if (abortControllers.get(fileState.id) === controller) {
            deactivate(fileState);
        }

        return;
    }

    await finishIfComplete(fileState);
}

async function finishIfComplete(fileState: UploadFileState): Promise<void> {
    // Synchronous check-and-flip: only one caller can win.
    if (
        fileState.status === 'uploading' &&
        fileState.chunks.every((c) => c.status === 'done')
    ) {
        await finalizeFile(fileState);
    }
}

/** Puts a file whose session exists into the shared rotation. */
function activateFile(fileState: UploadFileState): void {
    abortControllers.get(fileState.id)?.abort();

    const controller = new AbortController();
    abortControllers.set(fileState.id, controller);
    fileState.status = 'uploading';
    fileState.errorCode = null;
    fileState.errorChunkIndex = null;

    // Speed is measured from NOW. Without a baseline the first response
    // would be divided by the (tiny) gap since its own sample was created,
    // and the readout would spike to GB/s for the first seconds.
    progressSamples.set(fileState.id, {
        lastBytes: fileState.bytesUploaded,
        lastTime: performance.now(),
        emaSpeed: 0,
    });

    scheduler.register(fileState.id, {
        claim: () => {
            if (controller.signal.aborted || fileState.status !== 'uploading') {
                return null;
            }

            const now = Date.now();
            const next = fileState.chunks.find(
                (c) =>
                    c.status === 'pending' &&
                    (retryAt.get(retryKey(fileState, c)) ?? 0) <= now,
            );

            if (!next) {
                return null;
            }

            next.status = 'slicing'; // claimed synchronously

            return () => runChunkJob(fileState, next, controller);
        },
    });

    // Everything may already be on the server (a resume).
    void finishIfComplete(fileState);
}

// --- init (parallel, capped) -------------------------------------------------

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

/**
 * One init, with the server's own pushback handled here: 429 and 502/503/504
 * are retried by themselves (Retry-After honoured), each attempt re-entering
 * the priority lane so the wait never holds a slot. Anything else, and a
 * transient failure that outlasts its retries, surfaces to `startFile`.
 */
async function initWithRetry(
    fileState: UploadFileState,
): Promise<InitUploadResponse | null> {
    for (let attempt = 1; ; attempt++) {
        try {
            const response = await scheduler.runPriority(async () => {
                fileState.status = 'initializing';

                return initUpload({
                    oeuvre_id: fileState.oeuvreId,
                    filename: fileState.filename,
                    size_bytes: fileState.size,
                    mime: fileState.mime,
                    college_oeuvre_file_id: fileState.requirementId,
                });
            });
            fileState.notice = null;

            return response;
        } catch (error) {
            const failure =
                error instanceof UploadHttpError
                    ? classifyFailure(error)
                    : null;

            if (
                failure === null ||
                !isTransient(failure) ||
                attempt >= MAX_TRANSIENT_ATTEMPTS
            ) {
                throw error;
            }

            logUploadFailure('init', fileState, error);
            fileState.notice = noticeFor(failure);
            await sleep(transientDelayMs(failure, attempt));

            // Cancelled while waiting: nothing was created, nothing to undo.
            if (!state.files.has(fileState.id)) {
                return null;
            }
        }
    }
}

/**
 * Fires as soon as the file is chosen. Inits have priority over queued
 * chunks (see the scheduler), so a small file is initialised and joins the
 * rotation immediately, even while another file is mid-upload.
 */
async function startFile(fileState: UploadFileState): Promise<void> {
    try {
        const response = await initWithRetry(fileState);

        if (response === null) {
            return;
        }

        // Cancelled while the init was in flight: release the session the
        // server just created rather than orphaning it.
        if (!state.files.has(fileState.id)) {
            void abortUploadSession(response.uuid).catch(() => {});

            return;
        }

        applyInitResponse(fileState, response);
        persist(fileState);

        if (fileState.status === 'initializing') {
            activateFile(fileState);
        }
    } catch (error) {
        if (error instanceof UploadHttpError) {
            failWith(fileState, classifyFailure(error));
        } else {
            fileState.status = 'failed';
            setError(fileState, 'network');
        }

        logUploadFailure('init', fileState, error);
    }
}

// --- public actions --------------------------------------------------------

function enqueueFile(
    file: File,
    oeuvreId: number,
    requirement: UploadRequirement | null = null,
): { ok: true; id: string } | { ok: false; reason: 'extension' | 'size' } {
    const validation = requirement
        ? validateFileForRequirement(file, requirement)
        : validateFile(file);

    if (!validation.ok) {
        return { ok: false, reason: validation.reason };
    }

    const id = generateClientId();

    const fileState: UploadFileState = {
        id,
        oeuvreId,
        requirementId: requirement?.id ?? null,
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
        neededQuotaBytes: null,
        errorMessage: null,
        errorStatus: null,
        notice: null,
        bytesUploaded: 0,
        speedBps: 0,
        etaSeconds: null,
        expiresAt: null,
        mediaFileUuid: null,
        needsFileReselect: false,
    };

    state.files.set(id, fileState);
    state.order.push(id);
    // Through the reactive map, so later status writes are tracked.
    void startFile(state.files.get(id) as UploadFileState);

    return { ok: true, id };
}

function pauseFile(fileId: string): void {
    const fileState = state.files.get(fileId);

    if (!fileState) {
        return;
    }

    if (
        fileState.status === 'uploading' ||
        fileState.status === 'initializing'
    ) {
        fileState.status = 'paused';
    }

    clearRetries(fileState);
    deactivate(fileState);
}

/**
 * A paused or failed file RESUMES the session it already has: nothing is
 * re-initialised, the server's tracker still knows which chunks arrived, and
 * the quota reserved at init is neither released nor charged twice.
 *
 * A new session starts only when the old one cannot continue — it expired —
 * or never existed (the init itself failed). The old one is aborted first so
 * its reservation is released before the new init reserves again.
 */
function resumeFile(fileId: string): void {
    const fileState = state.files.get(fileId);

    if (!fileState?.file) {
        return;
    }

    if (
        fileState.status === 'expired' ||
        (fileState.status === 'failed' && !fileState.sessionUuid)
    ) {
        void restartWithNewSession(fileState);

        return;
    }

    if (!fileState.sessionUuid) {
        return;
    }

    if (fileState.status !== 'paused' && fileState.status !== 'failed') {
        return;
    }

    fileState.chunks.forEach((c) => {
        if (c.status !== 'done') {
            c.status = 'pending';
            c.attempts = 0;
        }
    });
    clearRetries(fileState);
    activateFile(fileState);
}

async function restartWithNewSession(
    fileState: UploadFileState,
): Promise<void> {
    const oldSession = fileState.sessionUuid;

    deactivate(fileState);
    clearRetries(fileState);
    clearPersisted(fileState.id);
    progressSamples.delete(fileState.id);

    // Flipped synchronously so a double click cannot start two sessions.
    fileState.status = 'queued';
    fileState.sessionUuid = null;
    fileState.chunks = [];
    fileState.bytesUploaded = 0;
    fileState.speedBps = 0;
    fileState.etaSeconds = null;
    fileState.errorCode = null;
    fileState.errorChunkIndex = null;
    fileState.errorMessage = null;
    fileState.errorStatus = null;
    fileState.notice = null;
    fileState.mediaFileUuid = null;

    if (oldSession) {
        try {
            await abortUploadSession(oldSession);
        } catch {
            // Best-effort: an expired session no longer counts as reserved
            // on the server in any case.
        }
    }

    await startFile(fileState);
}

async function cancelFile(fileId: string): Promise<void> {
    const fileState = state.files.get(fileId);

    if (!fileState) {
        return;
    }

    deactivate(fileState);
    clearRetries(fileState);

    // Removed before the (awaited) server call so the file disappears at
    // once and an in-flight init sees it was cancelled.
    clearPersisted(fileId);
    progressSamples.delete(fileId);
    state.files.delete(fileId);
    state.order = state.order.filter((id) => id !== fileId);

    if (fileState.sessionUuid && fileState.status !== 'completed') {
        try {
            await abortUploadSession(fileState.sessionUuid);
        } catch {
            // Best-effort — an orphaned .part file is reclaimed by the P7
            // retention job regardless of whether this call succeeds.
        }
    }
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
        activateFile(fileState);
    } catch (error) {
        if (error instanceof UploadHttpError) {
            failWith(fileState, classifyFailure(error));

            return;
        }

        fileState.status = 'failed';
        setError(fileState, 'network');
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
            f.status === 'queued' ||
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
        limits: readLimits,
        enqueueFile,
        pauseFile,
        resumeFile,
        cancelFile,
        resumeWithReselectedFile,
    };
}
