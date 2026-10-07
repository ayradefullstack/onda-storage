/**
 * Single-attempt HTTP calls against the P3 upload endpoints. Retry/backoff
 * orchestration lives in the store (it needs chunk/file state this layer
 * doesn't have) — this file only knows how to make one correct request and
 * report what happened.
 *
 * CSRF follows the same contract Inertia's own client relies on for
 * stateful requests: read the (encrypted, URL-encoded) `XSRF-TOKEN` cookie
 * Laravel sets, decode it, and echo it back as `X-XSRF-TOKEN` — no new
 * mechanism invented.
 */
import uploads from '@/routes/uploads';
import type {
    ChunkUploadResponse,
    CompleteUploadResponse,
    InitUploadResponse,
    UploadStatusResponse,
} from '@/types/upload';

const XSRF_COOKIE_NAME = 'XSRF-TOKEN';

export class UploadHttpError extends Error {
    constructor(
        public readonly status: number,
        public readonly body: unknown,
        /** Seconds from a `Retry-After` header, when the server sent one. */
        public readonly retryAfterSeconds: number | null = null,
    ) {
        super(`Upload request failed with HTTP ${status}`);
        this.name = 'UploadHttpError';
    }
}

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match?.[1] ? decodeURIComponent(match[1]) : null;
}

function baseHeaders(extra: Record<string, string>): HeadersInit {
    const xsrfToken = readCookie(XSRF_COOKIE_NAME);

    return {
        Accept: 'application/json',
        ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
        ...extra,
    };
}

async function parseJsonSafely(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

/** `Retry-After` is delta-seconds or an HTTP date; anything else is ignored. */
function parseRetryAfter(value: string | null): number | null {
    if (value === null || value.trim() === '') {
        return null;
    }

    const seconds = Number(value);

    if (Number.isFinite(seconds)) {
        return Math.max(0, seconds);
    }

    const at = Date.parse(value);

    return Number.isNaN(at) ? null : Math.max(0, (at - Date.now()) / 1000);
}

async function assertOk(response: Response): Promise<void> {
    if (!response.ok) {
        throw new UploadHttpError(
            response.status,
            await parseJsonSafely(response),
            parseRetryAfter(response.headers.get('Retry-After')),
        );
    }
}

export interface InitUploadPayload {
    oeuvre_id: number;
    filename: string;
    size_bytes: number;
    mime: string;
    /** Required for a classified oeuvre; InitUpload checks it server-side. */
    college_oeuvre_file_id: number | null;
}

export async function initUpload(
    payload: InitUploadPayload,
    signal?: AbortSignal,
): Promise<InitUploadResponse> {
    const response = await fetch(uploads.init.url(), {
        method: 'POST',
        credentials: 'same-origin',
        signal,
        headers: baseHeaders({ 'Content-Type': 'application/json' }),
        body: JSON.stringify(payload),
    });

    await assertOk(response);

    return (await response.json()) as InitUploadResponse;
}

export async function uploadChunk(
    sessionUuid: string,
    index: number,
    buffer: ArrayBuffer,
    crc32Hex: string,
    signal?: AbortSignal,
): Promise<ChunkUploadResponse> {
    const response = await fetch(
        uploads.chunk.url({ session: sessionUuid, index }),
        {
            method: 'POST',
            credentials: 'same-origin',
            signal,
            headers: baseHeaders({
                'Content-Type': 'application/octet-stream',
                'X-Chunk-CRC32': crc32Hex,
            }),
            body: buffer,
        },
    );

    await assertOk(response);

    return (await response.json()) as ChunkUploadResponse;
}

export async function completeUpload(
    sessionUuid: string,
    signal?: AbortSignal,
): Promise<CompleteUploadResponse> {
    const response = await fetch(
        uploads.complete.url({ session: sessionUuid }),
        {
            method: 'POST',
            credentials: 'same-origin',
            signal,
            headers: baseHeaders({ 'Content-Type': 'application/json' }),
        },
    );

    await assertOk(response);

    return (await response.json()) as CompleteUploadResponse;
}

export async function abortUploadSession(
    sessionUuid: string,
    signal?: AbortSignal,
): Promise<void> {
    const response = await fetch(uploads.abort.url({ session: sessionUuid }), {
        method: 'DELETE',
        credentials: 'same-origin',
        signal,
        headers: baseHeaders({}),
    });

    await assertOk(response);
}

export async function fetchUploadStatus(
    sessionUuid: string,
    signal?: AbortSignal,
): Promise<UploadStatusResponse> {
    const response = await fetch(uploads.status.url({ session: sessionUuid }), {
        method: 'GET',
        credentials: 'same-origin',
        signal,
        headers: baseHeaders({}),
    });

    await assertOk(response);

    return (await response.json()) as UploadStatusResponse;
}
