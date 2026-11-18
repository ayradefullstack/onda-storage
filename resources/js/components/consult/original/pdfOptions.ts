import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

/**
 * PDF.js configuration for the original-inline viewer.
 *
 * - The worker is OUR build's asset (Vite `?url`), never a CDN.
 * - `isEvalSupported: false` removes pdf.js's `new Function` fast path and
 *   `enableXfa: false` its XFA form engine: nothing in a deposited PDF can
 *   run script in this origin.
 * - `disableAutoFetch` + a 1 MiB `rangeChunkSize` load a large PDF
 *   progressively, page by page, over HTTP Range.
 */
export const RANGE_CHUNK_SIZE = 1024 * 1024;

export const PDF_WORKER_URL: string = workerUrl;

export function pdfDocumentOptions() {
    return {
        isEvalSupported: false,
        enableXfa: false,
        disableAutoFetch: true,
        rangeChunkSize: RANGE_CHUNK_SIZE,
    } as const;
}
