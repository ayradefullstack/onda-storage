import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    fetchSummary,
    insertAtCursor,
    postDecision,
    REASON_MAX,
    REASON_MIN,
    reasonLength,
    reasonState,
} from './decisions';
import { relativeTime } from './relativeTime';

describe('the rejection reason', () => {
    it('is counted trimmed, like the server counts it', () => {
        expect(reasonLength('   abc   ')).toBe(3);
        expect(reasonLength('  \n ')).toBe(0);
    });

    it('is valid from 20 to 2000 characters', () => {
        expect(reasonState('')).toBe('empty');
        expect(reasonState('     ')).toBe('empty');
        expect(reasonState('a'.repeat(REASON_MIN - 1))).toBe('short');
        expect(reasonState(`  ${'a'.repeat(REASON_MIN - 1)}  `)).toBe('short');
        expect(reasonState('a'.repeat(REASON_MIN))).toBe('ok');
        expect(reasonState('a'.repeat(REASON_MAX))).toBe('ok');
        expect(reasonState('a'.repeat(REASON_MAX + 1))).toBe('long');
    });
});

describe('insertAtCursor', () => {
    it('inserts into an empty field without padding', () => {
        expect(insertAtCursor('', 'Format non conforme', 0, 0)).toEqual({
            text: 'Format non conforme',
            cursor: 19,
        });
    });

    it('inserts at the cursor, spaced from its neighbours', () => {
        const result = insertAtCursor('Bonjour.Merci.', 'X', 8, 8);

        expect(result.text).toBe('Bonjour. X Merci.');
        expect(result.text.slice(0, result.cursor)).toBe('Bonjour. X');
    });

    it('does not double a space that is already there', () => {
        expect(insertAtCursor('Bonjour. ', 'X', 9, 9).text).toBe('Bonjour. X');
        expect(insertAtCursor('a  b', 'X', 2, 2).text).toBe('a X b');
    });

    it('replaces the selection', () => {
        expect(insertAtCursor('un deux trois', 'DEUX', 3, 7).text).toBe(
            'un DEUX trois',
        );
    });

    it('clamps an out-of-range cursor to the end', () => {
        expect(insertAtCursor('abc', 'X', 99, 99).text).toBe('abc X');
    });
});

describe('postDecision tells the outcomes apart', () => {
    afterEach(() => vi.unstubAllGlobals());

    const respond = (status: number, body: unknown) =>
        vi.fn(async () => ({
            ok: status >= 200 && status < 300,
            status,
            json: async () => body,
        }));

    it('posts JSON with the XSRF token and asks for JSON back', async () => {
        const fetchMock = respond(200, {
            status: 'registered',
            replayed: false,
        });

        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('document', { cookie: 'a=b; XSRF-TOKEN=abc%20123' });

        const outcome = await postDecision('/admin/oeuvres/x/reject', {
            reason: 'r',
        });

        expect(outcome).toEqual({
            kind: 'done',
            status: 'registered',
            replayed: false,
        });

        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit & { headers: Record<string, string> },
        ];

        expect(url).toBe('/admin/oeuvres/x/reject');
        expect(init.method).toBe('POST');
        expect(init.headers.Accept).toBe('application/json');
        expect(init.headers['X-XSRF-TOKEN']).toBe('abc 123');
        expect(JSON.parse(String(init.body))).toEqual({ reason: 'r' });
    });

    it('a replay is done, flagged', async () => {
        vi.stubGlobal(
            'fetch',
            respond(200, { status: 'registered', replayed: true }),
        );

        expect(await postDecision('/x')).toMatchObject({
            kind: 'done',
            replayed: true,
        });
    });

    it('409 is a conflict carrying who and when', async () => {
        const conflict = {
            kind: 'decided',
            by: 'Amina',
            at: '2026-10-12T09:30:00+00:00',
            status: 'registered',
        };

        vi.stubGlobal('fetch', respond(409, { message: 'x', conflict }));

        expect(await postDecision('/x')).toEqual({
            kind: 'conflict',
            conflict,
        });
    });

    it('422 with problems is a refusal carrying the list', async () => {
        const problems = [
            { code: 'slot_empty', params: { name: 'Contrat' }, message: 'm' },
        ];

        vi.stubGlobal(
            'fetch',
            respond(422, {
                message: 'x',
                errors: { approval: ['m'] },
                problems,
            }),
        );

        expect(await postDecision('/x')).toEqual({
            kind: 'refused',
            problems,
        });
    });

    it('422 without problems is a validation error', async () => {
        vi.stubGlobal(
            'fetch',
            respond(422, { message: 'x', errors: { reason: ['too short'] } }),
        );

        expect(await postDecision('/x')).toEqual({
            kind: 'invalid',
            errors: { reason: ['too short'] },
        });
    });

    it('a network error or an unexpected status is "failed", never a throw', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new TypeError('offline');
            }),
        );
        expect(await postDecision('/x')).toEqual({ kind: 'failed' });

        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: false,
                status: 419,
                json: async () => {
                    throw new SyntaxError('html');
                },
            })),
        );
        expect(await postDecision('/x')).toEqual({ kind: 'failed' });
    });
});

describe('fetchSummary', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('asks for JSON on the same origin and returns the summary', async () => {
        const summary = { uuid: 'o1', allowed: ['open_review'] };
        const mock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => summary,
        }));
        vi.stubGlobal('fetch', mock);

        await expect(
            fetchSummary('/admin/oeuvres/o1/decision'),
        ).resolves.toEqual({ kind: 'ok', summary });

        const [url, init] = mock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];
        expect(url).toBe('/admin/oeuvres/o1/decision');
        expect(init.method ?? 'GET').toBe('GET');
        expect(init.credentials).toBe('same-origin');
        expect((init.headers as Record<string, string>).Accept).toBe(
            'application/json',
        );
    });

    it('reports a refusal with its status, and a network error without one', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: false,
                status: 403,
                json: async () => ({}),
            })),
        );
        await expect(fetchSummary('/x')).resolves.toEqual({
            kind: 'failed',
            status: 403,
        });

        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new TypeError('offline');
            }),
        );
        await expect(fetchSummary('/x')).resolves.toEqual({
            kind: 'failed',
            status: null,
        });
    });
});

describe('relativeTime', () => {
    const now = Date.parse('2026-10-13T12:00:00Z');

    it('picks the largest unit that fits, in the reader language', () => {
        expect(relativeTime('2026-10-13T09:00:00Z', 'en', now)).toBe(
            '3 hours ago',
        );
        expect(relativeTime('2026-10-11T12:00:00Z', 'fr', now)).toBe(
            'avant-hier',
        );
        expect(relativeTime('2026-10-13T11:59:50Z', 'en', now)).toBe(
            'this minute',
        );
    });

    it('uses Latin digits in Arabic', () => {
        const text = relativeTime('2026-10-13T07:00:00Z', 'ar', now);

        expect(text).toMatch(/5/);
        expect(text).not.toMatch(/[٠-٩]/);
    });

    it('returns the input for an unparsable date', () => {
        expect(relativeTime('nope', 'fr', now)).toBe('nope');
    });
});
