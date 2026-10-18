/**
 * The officer's decisions, as the review dialog sees them: the server's
 * summary of a deposit (which decisions it offers right now, and why
 * "Accepter" is greyed), and one function that posts a decision and tells
 * its outcomes apart.
 *
 * The page never decides which buttons to show: `allowed` comes from
 * GET admin/oeuvres/{oeuvre}/decision, computed with the same rules the
 * POST routes enforce. Even so it is advisory — the server re-decides every
 * time, under a row lock — so a decision it offered can still be refused,
 * and that refusal is one of the outcomes below, never an error.
 */

export type OeuvreStatus =
    'draft' | 'submitted' | 'under_review' | 'registered' | 'rejected';

/** One reason a registration is refused: a code and what it is about. */
export interface Problem {
    code: string;
    params: Record<string, string | number | null>;
    message: string;
}

export interface Conflict {
    /** `held`: someone is reviewing it. `decided`: someone decided it. */
    kind: 'held' | 'decided' | 'moved';
    by: string | null;
    at: string | null;
    status: string | null;
}

export type Decision = 'review' | 'approve' | 'reject';

export type Outcome =
    | { kind: 'done'; status: string; replayed: boolean }
    | { kind: 'conflict'; conflict: Conflict }
    | { kind: 'refused'; problems: Problem[] }
    | { kind: 'invalid'; errors: Record<string, string[]> }
    | { kind: 'failed' };

export type AllowedDecision =
    'open_review' | 'take_over' | 'approve' | 'reject';

/** GET admin/oeuvres/{oeuvre}/decision. */
export interface DecisionSummary {
    uuid: string;
    status: OeuvreStatus;
    reference: string;
    title: string | null;
    author: { name: string } | null;
    college: string | null;
    createdAt: string | null;
    submittedAt: string | null;
    /** Only while under review: who has it open. */
    holder: { id: string; name: string } | null;
    heldByMe: boolean;
    checklist: {
        noFiles: boolean;
        /** Unconditional required documents with no ready file. */
        requiredMissing: string[];
        /** Every file the pipeline has not finished with, or gave up on. */
        filesNotReady: { name: string; status: string }[];
        /** Advisory: documents that may not apply to this deposit. */
        conditionalMissing: string[];
        /** Every file an officer has not accepted (pending or rejected). */
        filesNotAccepted?: { name: string; reviewStatus: string }[];
        /**
         * Advisory, never blocking: slots whose rejected file the author
         * removed without a replacement yet (null = the unclassified area).
         */
        openReplacementSlots?: (string | null)[];
    };
    /** Rejected files and their reasons: the reject screen's quick insert. */
    rejectedFiles?: { name: string; reason: string }[];
    allowed: AllowedDecision[];
    /** Permitted by status and holder, refused by the checklist. */
    blocked: AllowedDecision[];
    lastDecision: { to: string; by: string | null; at: string | null } | null;
}

export type SummaryResult =
    | { kind: 'ok'; summary: DecisionSummary }
    | { kind: 'failed'; status: number | null };

/** Never throws: a network failure or a refusal is `failed`. */
export async function fetchSummary(url: string): Promise<SummaryResult> {
    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return { kind: 'failed', status: response.status };
        }

        return {
            kind: 'ok',
            summary: (await response.json()) as DecisionSummary,
        };
    } catch {
        return { kind: 'failed', status: null };
    }
}

/** A quick reason: what the chip says, and the text it inserts. */
export interface ReasonChip {
    id: string;
    label: string;
    text: string;
}

export const REASON_MIN = 20;
export const REASON_MAX = 2000;

export type ReasonState = 'empty' | 'short' | 'ok' | 'long';

/** Counted as the server counts it: trimmed. */
export function reasonLength(reason: string): number {
    return [...reason.trim()].length;
}

export function reasonState(reason: string): ReasonState {
    const length = reasonLength(reason);

    if (length === 0) {
        return 'empty';
    }

    if (length < REASON_MIN) {
        return 'short';
    }

    return length > REASON_MAX ? 'long' : 'ok';
}

/**
 * Put a quick reason where the cursor is (replacing a selection), spaced
 * from its neighbours so two chips in a row still read as sentences.
 */
export function insertAtCursor(
    text: string,
    insertion: string,
    start: number,
    end: number,
): { text: string; cursor: number } {
    const from = Math.max(0, Math.min(start, text.length));
    const to = Math.max(from, Math.min(end, text.length));
    const before = text.slice(0, from);
    const after = text.slice(to);
    const lead = before !== '' && !/\s$/.test(before) ? ' ' : '';
    const trail = after !== '' && !/^\s/.test(after) ? ' ' : '';
    const inserted = `${lead}${insertion}${trail}`;

    return {
        text: `${before}${inserted}${after}`,
        cursor: before.length + lead.length + insertion.length,
    };
}

export function xsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='));

    return match === undefined
        ? null
        : decodeURIComponent(match.slice('XSRF-TOKEN='.length));
}

/**
 * POST one decision. JSON, not an Inertia visit: the dialogs need the
 * problem list and the conflict's author and date, which a redirect cannot
 * carry. The caller reloads its props afterwards.
 *
 * Never throws: a network failure is the `failed` outcome.
 */
export async function postDecision(
    url: string,
    body: Record<string, unknown> = {},
): Promise<Outcome> {
    let response: Response;

    try {
        const token = xsrfToken();

        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token === null ? {} : { 'X-XSRF-TOKEN': token }),
            },
            body: JSON.stringify(body),
        });
    } catch {
        return { kind: 'failed' };
    }

    let payload: Record<string, unknown> = {};

    try {
        payload = (await response.json()) as Record<string, unknown>;
    } catch {
        // A non-JSON body (a 419 page, a proxy error) is handled by status.
    }

    if (response.ok) {
        return {
            kind: 'done',
            status: String(payload.status ?? ''),
            replayed: payload.replayed === true,
        };
    }

    if (response.status === 409 && payload.conflict !== undefined) {
        return { kind: 'conflict', conflict: payload.conflict as Conflict };
    }

    if (response.status === 422 && Array.isArray(payload.problems)) {
        return { kind: 'refused', problems: payload.problems as Problem[] };
    }

    if (response.status === 422 && payload.errors !== undefined) {
        return {
            kind: 'invalid',
            errors: payload.errors as Record<string, string[]>,
        };
    }

    return { kind: 'failed' };
}
