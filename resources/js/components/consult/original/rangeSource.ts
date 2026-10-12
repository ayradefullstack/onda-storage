import { PDFDataRangeTransport } from 'pdfjs-dist';
import { RANGE_CHUNK_SIZE } from './pdfOptions';

/** Raised when the signed URL is no longer accepted (expired / refused). */
export class OriginalExpiredError extends Error {
    constructor(readonly status: number) {
        super(`original refused with ${status}`);
    }
}

export type FetchLike = typeof fetch;

/** Total size from a `Content-Range: bytes a-b/total` header. */
export function totalFromContentRange(header: string | null): number | null {
    const match = /^bytes \d+-\d+\/(\d+)$/.exec(header ?? '');

    return match === null ? null : Number(match[1]);
}

async function fetchRange(
    fetchFn: FetchLike,
    url: string,
    begin: number,
    end: number,
): Promise<{ bytes: Uint8Array; total: number | null }> {
    const response = await fetchFn(url, {
        headers: { Range: `bytes=${begin}-${end - 1}` },
        credentials: 'same-origin',
    });

    if (response.status === 403 || response.status === 401) {
        throw new OriginalExpiredError(response.status);
    }

    if (response.status !== 206 && response.status !== 200) {
        throw new Error(`unexpected status ${response.status}`);
    }

    return {
        bytes: new Uint8Array(await response.arrayBuffer()),
        total: totalFromContentRange(response.headers.get('Content-Range')),
    };
}

/**
 * Why this exists instead of `getDocument({ url })`: the server never answers
 * with more than a capped slice, even to a request WITHOUT a Range header, and
 * pdf.js sizes the whole document from the first response's Content-Length.
 * We read the real total from Content-Range of a first 1 MiB slice, then hand
 * pdf.js a range transport that fetches 1 MiB at a time.
 */
export async function openRangeTransport(
    url: string,
    fetchFn: FetchLike = fetch,
    onFailure: (error: unknown) => void = () => undefined,
): Promise<PDFDataRangeTransport> {
    const first = await fetchRange(fetchFn, url, 0, RANGE_CHUNK_SIZE);
    const total = first.total ?? first.bytes.byteLength;

    class SignedRangeTransport extends PDFDataRangeTransport {
        requestDataRange(begin: number, end: number): void {
            this.load(begin, Math.min(end, total)).catch(onFailure);
        }

        /**
         * pdf.js matches a delivered chunk to its pending request by the
         * request's EXACT start offset, so a request is answered with ONE
         * `onDataRange(begin, bytes)`: it is fetched in 1 MiB slices (never
         * one large request) and joined before delivery. A request is
         * bounded by what pdf.js asked for, a few MiB at most.
         */
        private async load(begin: number, end: number): Promise<void> {
            const out = new Uint8Array(end - begin);
            let filled = 0;

            for (let at = begin; at < end; at += RANGE_CHUNK_SIZE) {
                const { bytes } = await fetchRange(
                    fetchFn,
                    url,
                    at,
                    Math.min(at + RANGE_CHUNK_SIZE, end),
                );
                out.set(bytes.subarray(0, out.length - filled), filled);
                filled += bytes.byteLength;
            }

            this.onDataRange(begin, out);
        }
    }

    // A file that fits in the first slice has nothing more to stream: without
    // `progressiveDone` pdf.js waits for more data and the document never opens.
    return new SignedRangeTransport(
        total,
        first.bytes,
        first.bytes.byteLength >= total,
    );
}
