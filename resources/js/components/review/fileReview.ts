/**
 * The officer's decision on ONE file of a deposit, as the admin pages see
 * it. The server computes what may be done (`allowed`, per file) with the
 * rules DecideMediaFile enforces under a lock; the page only renders it.
 *
 * `media_files.review_status` leaves the backend as its key. There are no
 * generated TS enums yet (Phase 2b), so this is the one place the three
 * keys are written down.
 */
import { postDecision } from './decisions';
import type { Outcome } from './decisions';

export const REVIEW_STATUS = {
    PENDING: 'pending',
    ACCEPTED: 'accepted',
    REJECTED: 'rejected',
} as const;

export type ReviewStatus = (typeof REVIEW_STATUS)[keyof typeof REVIEW_STATUS];

/** What the server offers for a file: DecideMediaFile::allowed(). */
export type FileAction = 'accept' | 'reject' | 'reset';

/** Why `actions` is empty. */
export type FileBlockedReason =
    'not_under_review' | 'not_holder' | 'file_not_ready' | 'oeuvre_decided';

export interface FileAllowed {
    actions: FileAction[];
    reason: FileBlockedReason | null;
}

/** The decision each action posts. */
export const ACTION_DECISION: Record<FileAction, ReviewStatus> = {
    accept: REVIEW_STATUS.ACCEPTED,
    reject: REVIEW_STATUS.REJECTED,
    reset: REVIEW_STATUS.PENDING,
};

/**
 * POST one file decision. Same outcomes as the oeuvre decisions: `done`,
 * `conflict` (409: held or decided), `invalid` (422 with the server's own
 * message), `failed`. Never throws.
 */
export function postFileDecision(
    url: string,
    decision: ReviewStatus,
    reason?: string,
): Promise<Outcome> {
    return postDecision(
        url,
        decision === REVIEW_STATUS.REJECTED
            ? { decision, reason: (reason ?? '').trim() }
            : { decision },
    );
}

/** The 422's own message, field by field: never a generic error. */
export function invalidMessage(
    errors: Record<string, string[]>,
): string | null {
    return errors.decision?.[0] ?? errors.reason?.[0] ?? null;
}

/**
 * The next file still `pending` after `current`, in list order, wrapping
 * round to the start; null when every other file has a decision.
 */
export function nextToReview<
    T extends { uuid: string; review_status?: string | null },
>(files: T[], current: string | null): T | null {
    const start = files.findIndex((file) => file.uuid === current);

    for (let step = 1; step <= files.length; step++) {
        const file = files[(start + step + files.length) % files.length];

        if (
            file !== undefined &&
            file.uuid !== current &&
            file.review_status === REVIEW_STATUS.PENDING
        ) {
            return file;
        }
    }

    return null;
}
