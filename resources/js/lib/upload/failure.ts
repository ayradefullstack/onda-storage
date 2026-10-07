/**
 * One place that decides what an upload request's failure MEANS and what the
 * client does about it. Pure: no Vue, no fetch — it is driven by the status
 * and body of an `UploadHttpError`, so every row of the table below is unit
 * tested.
 *
 *   status          meaning                         behaviour
 *   413 (+ key)     quota: room left / needed       stop
 *   422             server validation message       stop
 *   429             server busy                     retry by itself, honouring Retry-After
 *   502/503/504     temporary server problem        retry by itself, with backoff
 *   419             signed out                      stop (log in again)
 *   410             upload session expired          stop (fresh session on retry)
 *   anything else   unknown status                  stop, and show the status code
 *
 * A network failure (no response at all) is handled like a temporary problem
 * by the callers; it is not a status and never reaches here.
 */
import type { UploadHttpError } from '@/lib/uploadClient';

export type UploadFailure =
    | {
          kind: 'quota';
          remainingBytes: number | null;
          neededBytes: number | null;
      }
    | { kind: 'validation'; message: string | null }
    | { kind: 'busy'; retryAfterMs: number | null }
    | { kind: 'temporary'; retryAfterMs: number | null }
    | { kind: 'authExpired' }
    | { kind: 'sessionGone' }
    | { kind: 'unknown'; status: number };

/** How many times a busy/temporary answer is retried before the file fails. */
export const MAX_TRANSIENT_ATTEMPTS = 8;

/** Ceiling for one wait, whatever `Retry-After` says. */
export const MAX_TRANSIENT_WAIT_MS = 60_000;

const TRANSIENT_BACKOFF_MS = [1000, 2000, 4000, 8000, 15000, 30000];

function numberOrNull(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/** The first human-readable validation message in a Laravel 422 body. */
export function validationMessage(body: unknown): string | null {
    if (typeof body !== 'object' || body === null) {
        return null;
    }

    const { errors, message } = body as {
        errors?: Record<string, unknown>;
        message?: unknown;
    };

    if (errors && typeof errors === 'object') {
        for (const messages of Object.values(errors)) {
            if (Array.isArray(messages) && typeof messages[0] === 'string') {
                return messages[0];
            }
        }
    }

    return typeof message === 'string' && message !== '' ? message : null;
}

export function classifyFailure(error: UploadHttpError): UploadFailure {
    const retryAfterMs =
        error.retryAfterSeconds === null
            ? null
            : error.retryAfterSeconds * 1000;

    switch (error.status) {
        case 413: {
            const body = (error.body ?? {}) as Record<string, unknown>;

            return {
                kind: 'quota',
                remainingBytes: numberOrNull(body.remaining_bytes),
                neededBytes: numberOrNull(body.needed_bytes),
            };
        }

        case 422:
            return {
                kind: 'validation',
                message: validationMessage(error.body),
            };

        case 429:
            return { kind: 'busy', retryAfterMs };

        case 502:
        case 503:
        case 504:
            return { kind: 'temporary', retryAfterMs };

        case 419:
            return { kind: 'authExpired' };

        case 410:
            return { kind: 'sessionGone' };

        default:
            return { kind: 'unknown', status: error.status };
    }
}

/** True for the two kinds the client retries on its own. */
export function isTransient(
    failure: UploadFailure,
): failure is Extract<UploadFailure, { kind: 'busy' | 'temporary' }> {
    return failure.kind === 'busy' || failure.kind === 'temporary';
}

/**
 * How long to wait before attempt number `attempt` (1-based: the delay after
 * the first failure is `attempt = 1`). The longer of the server's
 * `Retry-After` and the backoff step, never beyond the ceiling.
 */
export function transientDelayMs(
    failure: Extract<UploadFailure, { kind: 'busy' | 'temporary' }>,
    attempt: number,
): number {
    const step = TRANSIENT_BACKOFF_MS[
        Math.min(Math.max(attempt, 1) - 1, TRANSIENT_BACKOFF_MS.length - 1)
    ] as number;

    return Math.min(
        MAX_TRANSIENT_WAIT_MS,
        Math.max(failure.retryAfterMs ?? 0, step),
    );
}
