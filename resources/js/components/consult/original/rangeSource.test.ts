import { describe, expect, it, vi } from 'vitest';

vi.mock('pdfjs-dist', () => ({
    PDFDataRangeTransport: class {
        constructor(
            public length: number,
            public initialData: Uint8Array,
            public progressiveDone = false,
        ) {}

        onDataRange = vi.fn();
    },
}));

import {
    OriginalExpiredError,
    openRangeTransport,
    totalFromContentRange,
} from './rangeSource';

const MIB = 1024 * 1024;

describe('Content-Range', () => {
    it('reads the total', () => {
        expect(totalFromContentRange('bytes 0-1048575/52428800')).toBe(
            52428800,
        );
        expect(totalFromContentRange('bytes */100')).toBeNull();
        expect(totalFromContentRange(null)).toBeNull();
    });
});

describe('the PDF range transport', () => {
    function server(total: number, status = 206) {
        const calls: string[] = [];
        const fetchFn = vi.fn(async (_url: unknown, init?: RequestInit) => {
            const range = (init?.headers as Record<string, string>).Range;
            calls.push(range);
            const [a, b] = range.replace('bytes=', '').split('-').map(Number);
            const end = Math.min(b, total - 1);

            return new Response(new Uint8Array(end - a + 1), {
                status,
                headers: { 'Content-Range': `bytes ${a}-${end}/${total}` },
            });
        }) as unknown as typeof fetch;

        return { fetchFn, calls };
    }

    it('takes the real total from Content-Range of a first 1 MiB slice', async () => {
        const { fetchFn, calls } = server(50 * MIB);
        const transport = await openRangeTransport('/signed', fetchFn);

        expect(calls).toEqual([`bytes=0-${MIB - 1}`]);
        expect(transport.length).toBe(50 * MIB);
    });

    it('marks a file that fits in the first slice as complete, so pdf.js does not wait for more', async () => {
        const small = await openRangeTransport(
            '/signed',
            server(300_000).fetchFn,
        );
        const large = await openRangeTransport(
            '/signed',
            server(50 * MIB).fetchFn,
        );

        expect(small.progressiveDone).toBe(true);
        expect(large.progressiveDone).toBe(false);
    });

    it('fetches 1 MiB slices with the signed url and answers pdf.js with ONE chunk at the exact begin offset', async () => {
        const { fetchFn, calls } = server(50 * MIB);
        const transport = await openRangeTransport('/signed', fetchFn);
        calls.length = 0;

        transport.requestDataRange(2 * MIB, 5 * MIB);
        await vi.waitFor(() => expect(calls).toHaveLength(3));

        expect(calls).toEqual([
            `bytes=${2 * MIB}-${3 * MIB - 1}`,
            `bytes=${3 * MIB}-${4 * MIB - 1}`,
            `bytes=${4 * MIB}-${5 * MIB - 1}`,
        ]);
        expect(
            (fetchFn as unknown as ReturnType<typeof vi.fn>).mock.calls.every(
                (call) => call[0] === '/signed',
            ),
        ).toBe(true);

        const delivered = (
            transport as unknown as { onDataRange: ReturnType<typeof vi.fn> }
        ).onDataRange;

        await vi.waitFor(() => expect(delivered).toHaveBeenCalledTimes(1));
        expect(delivered.mock.calls[0][0]).toBe(2 * MIB);
        expect((delivered.mock.calls[0][1] as Uint8Array).byteLength).toBe(
            3 * MIB,
        );
    });

    it('reports a 403 during a later range request through onFailure', async () => {
        const failures: unknown[] = [];
        let status = 206;
        const fetchFn = vi.fn(async (_u: unknown, init?: RequestInit) => {
            const range = (init?.headers as Record<string, string>).Range;
            const [a, b] = range.replace('bytes=', '').split('-').map(Number);

            return new Response(new Uint8Array(b - a + 1), {
                status,
                headers: { 'Content-Range': `bytes ${a}-${b}/${50 * MIB}` },
            });
        }) as unknown as typeof fetch;

        const transport = await openRangeTransport('/signed', fetchFn, (e) =>
            failures.push(e),
        );
        status = 403;
        transport.requestDataRange(2 * MIB, 3 * MIB);

        await vi.waitFor(() => expect(failures).toHaveLength(1));
        expect(failures[0]).toBeInstanceOf(OriginalExpiredError);
    });

    it('raises OriginalExpiredError on a 403 so the viewer can refresh the url', async () => {
        const { fetchFn } = server(10, 403);

        await expect(
            openRangeTransport('/signed', fetchFn),
        ).rejects.toBeInstanceOf(OriginalExpiredError);
    });
});
