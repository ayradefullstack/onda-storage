/**
 * Shared shapes for the P4 upload manager: local queue state, the P3
 * backend's JSON contracts, the chunker worker's message protocol, and the
 * sessionStorage persistence record used for resume-after-refresh.
 */

export type UploadFileStatus =
    | 'queued'
    | 'initializing'
    | 'uploading'
    | 'paused'
    | 'completing'
    | 'completed'
    | 'failed'
    | 'expired'
    | 'quota_exceeded';

/**
 * Keys under `upload.error.*` in the locale files — the store never builds
 * a human sentence itself, so every failure state is translatable and
 * renders correctly in all three languages instead of hardcoded English.
 */
export type UploadErrorCode =
    | 'crcMismatch'
    | 'network'
    | 'serverError'
    | 'missingChunks'
    | 'workerError'
    | 'authExpired'
    | 'unknown';

export type ChunkStatus =
    'pending' | 'slicing' | 'uploading' | 'done' | 'failed';

export interface UploadChunk {
    index: number;
    offset: number;
    length: number;
    status: ChunkStatus;
    attempts: number;
}

/**
 * `file` is null once a page refresh has occurred — the browser cannot
 * retain a File handle across reloads, so a resumed entry carries only the
 * metadata needed to validate a re-selected file before resuming.
 */
export interface UploadFileState {
    id: string;
    workId: number;
    file: File | null;
    filename: string;
    size: number;
    mime: string;
    extension: string;
    sessionUuid: string | null;
    chunkSize: number;
    totalChunks: number;
    chunks: UploadChunk[];
    status: UploadFileStatus;
    errorCode: UploadErrorCode | null;
    errorChunkIndex: number | null;
    remainingQuotaBytes: number | null;
    bytesUploaded: number;
    speedBps: number;
    etaSeconds: number | null;
    expiresAt: string | null;
    mediaFileUuid: string | null;
    needsFileReselect: boolean;
}

export interface InitUploadResponse {
    uuid: string;
    chunk_size: number;
    total_chunks: number;
    received: number[];
    expires_at: string;
}

export interface ChunkUploadResponse {
    index: number;
    received: number;
    total: number;
    bytes: number;
}

export interface UploadStatusResponse {
    uuid: string;
    status: string;
    chunk_size: number;
    total_chunks: number;
    received: number[];
    received_bytes: number;
    expires_at: string;
}

export interface CompleteUploadResponse {
    uuid: string;
    status: string;
}

export interface QuotaExceededResponse {
    message: string;
    remaining_bytes: number;
}

export interface MissingChunksResponse {
    message: string;
    missing: number[];
}

export interface WorkerSliceRequest {
    type: 'slice';
    fileId: string;
    index: number;
    blob: Blob;
}

export interface WorkerSliceResult {
    type: 'sliced';
    fileId: string;
    index: number;
    buffer: ArrayBuffer;
    crc32: string;
}

export interface WorkerSliceError {
    type: 'error';
    fileId: string;
    index: number;
    message: string;
}

export type WorkerResponse = WorkerSliceResult | WorkerSliceError;

/** sessionStorage record — enough to offer, and validate, a resume. */
export interface PersistedUpload {
    id: string;
    workId: number;
    sessionUuid: string;
    filename: string;
    size: number;
    mime: string;
    extension: string;
    chunkSize: number;
    totalChunks: number;
    expiresAt: string;
}

/** `media_files.status` — the P5 pipeline's terminal states. */
export type MediaFileStatus =
    | 'uploading'
    | 'assembling'
    | 'scanning'
    | 'processing'
    | 'ready'
    | 'failed'
    | 'quarantined';

export interface MediaFileSummary {
    uuid: string;
    original_name: string;
    extension: string;
    mime: string;
    size_bytes: number;
    status: MediaFileStatus;
    duration_sec: number | null;
    width: number | null;
    height: number | null;
    sha256_plain: string | null;
    variant_count: number;
    created_at: string;
}
