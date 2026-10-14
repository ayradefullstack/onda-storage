import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    acceptBlockers,
    availableActions,
    checksFromProblems,
    insertAtCursor,
    postDecision,
    REASON_MAX,
    REASON_MIN,
    reasonLength,
    reasonState,
} from './decisions';
import type { Checks, Holder } from './decisions';
import { relativeTime } from './relativeTime';

const me: Holder = { uuid: 'u1', name: 'Amina', is_you: true };
const other: Holder = { uuid: 'u2', name: 'Karim', is_you: false };
const clean: Checks = { files: 3, pending: 0, blocked: 0, missing_required: 0 };

const on = (actions: ReturnType<typeof availableActions>): string[] =>
    Object.entries(actions)
        .filter(([, offered]) => offered)
        .map(([name]) => name);

describe('availableActions follows the status machine', () => {
    it('a submitted deposit can only be opened', () => {
        expect(on(availableActions('submitted', null, true))).toEqual([
            'start',
        ]);
    });

    it('a deposit you hold (or nobody holds) can be accepted or rejected', () => {
        expect(on(availableActions('under_review', me, true))).toEqual([
            'accept',
            'reject',
        ]);
        expect(on(availableActions('under_review', null, true))).toEqual([
            'accept',
            'reject',
        ]);
    });

    it('a deposit another officer holds offers nothing but a take-over', () => {
        expect(on(availableActions('under_review', other, true))).toEqual([
            'takeOver',
        ]);
    });

    it.each(['registered', 'rejected', 'draft'])(
        'a %s deposit offers nothing',
        (status) => {
            expect(on(availableActions(status, me, true))).toEqual([]);
        },
    );

    it('offers nothing to someone who may not decide', () => {
        expect(on(availableActions('submitted', null, false))).toEqual([]);
        expect(on(availableActions('under_review', me, false))).toEqual([]);
    });
});

describe('acceptBlockers', () => {
    it('is empty for a complete deposit', () => {
        expect(acceptBlockers(clean)).toEqual([]);
    });

    it('names every reason, with its count', () => {
        expect(
            acceptBlockers({
                files: 4,
                pending: 2,
                blocked: 1,
                missing_required: 1,
            }),
        ).toEqual([
            { key: 'pendingFiles', count: 2 },
            { key: 'blockedFiles', count: 1 },
            { key: 'missingRequired', count: 1 },
        ]);
    });

    it('blocks a deposit with no file at all', () => {
        expect(acceptBlockers({ ...clean, files: 0 })).toEqual([
            { key: 'noFiles', count: 0 },
        ]);
    });

    it('rebuilds the same counts from the server problem list', () => {
        const problem = (code: string) => ({ code, params: {}, message: '' });
        const checks = checksFromProblems(
            [
                problem('file_in_pipeline'),
                problem('file_failed'),
                problem('file_quarantined'),
                problem('slot_empty'),
            ],
            5,
        );

        expect(checks).toMatchObject({
            files: 5,
            pending: 1,
            blocked: 2,
            missing_required: 1,
        });
        expect(checksFromProblems([problem('no_files')], 2).files).toBe(0);
    });
});

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
