import { describe, expect, it } from 'vitest';
import { UploadHttpError } from '@/lib/uploadClient';
import {
    classifyFailure,
    isTransient,
    MAX_TRANSIENT_WAIT_MS,
    transientDelayMs,
    validationMessage,
} from './failure';

describe('upload failure mapping', () => {
    it('413 is a quota failure that carries the room left and the room needed', () => {
        const failure = classifyFailure(
            new UploadHttpError(413, {
                error: 'quota_exceeded',
                remaining_bytes: 400,
                needed_bytes: 600,
            }),
        );

        expect(failure).toEqual({
            kind: 'quota',
            remainingBytes: 400,
            neededBytes: 600,
        });
        expect(isTransient(failure)).toBe(false);
    });

    it('413 without a usable body still stops, with unknown numbers', () => {
        expect(classifyFailure(new UploadHttpError(413, null))).toEqual({
            kind: 'quota',
            remainingBytes: null,
            neededBytes: null,
        });
    });

    it('422 is a validation failure with the server message, and never retried', () => {
        const failure = classifyFailure(
            new UploadHttpError(422, {
                message: 'The given data was invalid.',
                errors: { filename: ['The file type ".exe" is not accepted.'] },
            }),
        );

        expect(failure).toEqual({
            kind: 'validation',
            message: 'The file type ".exe" is not accepted.',
        });
        expect(isTransient(failure)).toBe(false);
    });

    it('422 falls back to the top-level message, then to null', () => {
        expect(validationMessage({ message: 'Slot is full.' })).toBe(
            'Slot is full.',
        );
        expect(validationMessage({ message: '' })).toBeNull();
        expect(validationMessage(null)).toBeNull();
        expect(validationMessage('nope')).toBeNull();
    });

    it('429 is "server busy" and retried, carrying Retry-After', () => {
        const failure = classifyFailure(new UploadHttpError(429, null, 8));

        expect(failure).toEqual({ kind: 'busy', retryAfterMs: 8000 });
        expect(isTransient(failure)).toBe(true);
    });

    it.each([502, 503, 504])(
        '%i is a temporary server problem and retried',
        (status) => {
            const failure = classifyFailure(
                new UploadHttpError(status, null, 3),
            );

            expect(failure).toEqual({ kind: 'temporary', retryAfterMs: 3000 });
            expect(isTransient(failure)).toBe(true);
        },
    );

    it('419 means signed out and is not retried', () => {
        const failure = classifyFailure(new UploadHttpError(419, null));

        expect(failure).toEqual({ kind: 'authExpired' });
        expect(isTransient(failure)).toBe(false);
    });

    it('410 means the upload session is gone', () => {
        expect(classifyFailure(new UploadHttpError(410, null))).toEqual({
            kind: 'sessionGone',
        });
    });

    it.each([400, 401, 403, 404, 409, 418, 500, 501, 507])(
        '%i is the only generic failure and carries its status code',
        (status) => {
            const failure = classifyFailure(new UploadHttpError(status, null));

            expect(failure).toEqual({ kind: 'unknown', status });
            expect(isTransient(failure)).toBe(false);
        },
    );
});

describe('transient retry delay', () => {
    const busy = (retryAfterMs: number | null) =>
        ({ kind: 'busy', retryAfterMs }) as const;

    it('backs off 1s, 2s, 4s, 8s, 15s, then holds at 30s', () => {
        const delays = [1, 2, 3, 4, 5, 6, 7, 8].map((attempt) =>
            transientDelayMs(busy(null), attempt),
        );

        expect(delays).toEqual([
            1000, 2000, 4000, 8000, 15000, 30000, 30000, 30000,
        ]);
    });

    it('waits at least as long as the server asked', () => {
        expect(transientDelayMs(busy(8000), 1)).toBe(8000);
        expect(transientDelayMs(busy(500), 3)).toBe(4000);
    });

    it('never waits beyond the ceiling, whatever Retry-After says', () => {
        expect(transientDelayMs(busy(3_600_000), 1)).toBe(
            MAX_TRANSIENT_WAIT_MS,
        );
    });
});
