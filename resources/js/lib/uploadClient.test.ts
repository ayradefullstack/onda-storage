import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { uploadChunk } from './uploadClient';

/**
 * Covers request *construction* only — the headers, method, URL and body
 * shape a chunk upload sends — by mocking `fetch`. It does not cover the
 * worker that produces the buffer/CRC being sent (see
 * `workers/chunker.worker.test.ts` for what that does and does not prove),
 * and it does not prove the server accepts what's sent — that's already
 * covered by the existing Pest suite and would add nothing here.
 */
describe('uploadChunk request construction', () => {
    beforeEach(() => {
        vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=abc%20123' });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('POSTs the raw ArrayBuffer as the body — never multipart — with the CRC header', async () => {
        const buffer = new Uint8Array([1, 2, 3]).buffer;
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ index: 0, received: 1, total: 1, bytes: 3 }),
        });
        vi.stubGlobal('fetch', fetchMock);

        await uploadChunk('session-uuid', 0, buffer, 'deadbeef');

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];

        expect(url).toBe('/uploads/session-uuid/chunk/0');
        expect(init.method).toBe('POST');
        expect(init.body).toBe(buffer);

        const headers = init.headers as Record<string, string>;
        expect(headers['Content-Type']).toBe('application/octet-stream');
        expect(headers['X-Chunk-CRC32']).toBe('deadbeef');
        expect(headers['X-XSRF-TOKEN']).toBe('abc 123');
    });

    it('throws with the response body attached when the server rejects the chunk', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 419,
                headers: new Headers(),
                json: async () => ({ message: 'CSRF token mismatch.' }),
            }),
        );

        await expect(
            uploadChunk('session-uuid', 0, new ArrayBuffer(1), 'deadbeef'),
        ).rejects.toMatchObject({ status: 419, retryAfterSeconds: null });
    });

    it('carries Retry-After (delta-seconds or HTTP date) onto the error', async () => {
        const reject = (retryAfter: string | null) =>
            vi.stubGlobal(
                'fetch',
                vi.fn().mockResolvedValue({
                    ok: false,
                    status: 429,
                    headers: new Headers(
                        retryAfter === null
                            ? {}
                            : { 'Retry-After': retryAfter },
                    ),
                    json: async () => ({ message: 'Too Many Attempts.' }),
                }),
            );
        const call = () =>
            uploadChunk('session-uuid', 0, new ArrayBuffer(1), 'deadbeef');

        reject('8');
        await expect(call()).rejects.toMatchObject({
            status: 429,
            retryAfterSeconds: 8,
        });

        reject(new Date(Date.now() + 30_000).toUTCString());
        await expect(call()).rejects.toMatchObject({
            retryAfterSeconds: expect.closeTo(30, -1),
        });

        reject('soon');
        await expect(call()).rejects.toMatchObject({ retryAfterSeconds: null });

        reject(null);
        await expect(call()).rejects.toMatchObject({ retryAfterSeconds: null });
    });
});
