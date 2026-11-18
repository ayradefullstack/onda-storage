import { OriginalExpiredError } from './rangeSource';

export type MediaFailure = 'expired' | 'unsupported';

/**
 * A media element's `error` event does not say WHY: an expired signed URL
 * (403) and an undecodable codec both surface as MEDIA_ERR_SRC_NOT_SUPPORTED.
 * Probe the URL with a 1-byte Range to tell them apart: refused -> the URL
 * expired (refresh and resume); served -> the browser cannot decode it.
 */
export async function classifyMediaFailure(
    url: string,
    fetchFn: typeof fetch = fetch,
): Promise<MediaFailure> {
    try {
        const response = await fetchFn(url, {
            headers: { Range: 'bytes=0-0' },
            credentials: 'same-origin',
        });

        return response.status === 403 || response.status === 401
            ? 'expired'
            : 'unsupported';
    } catch {
        return 'unsupported';
    }
}

export { OriginalExpiredError };

/** Remembers where playback was when the URL died, and hands it back once. */
export function createResumePoint() {
    let at = 0;

    return {
        remember(seconds: number): void {
            at = Number.isFinite(seconds) && seconds > 0 ? seconds : 0;
        },
        take(): number {
            const value = at;
            at = 0;

            return value;
        },
    };
}
