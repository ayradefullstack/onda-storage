/**
 * The officer's decisions, as the pages see them: which actions a deposit
 * offers right now, why "Accepter" is greyed, and one function that posts a
 * decision and tells its four outcomes apart.
 *
 * Everything here is ADVISORY. The server re-decides every time — under a
 * row lock, with the same gate — so a button this file offers can still be
 * refused, and that refusal is one of the outcomes below, never an error.
 */

export type OeuvreStatus =
    'draft' | 'submitted' | 'under_review' | 'registered' | 'rejected';

export interface Holder {
    uuid: string;
    name: string;
    is_you: boolean;
}

/** One reason a registration is refused: a code and what it is about. */
export interface Problem {
    code: string;
    params: Record<string, string | number | null>;
    message: string;
}

export interface Checks {
    files: number;
    /** Files the pipeline has not finished with. */
    pending: number;
    /** Files that failed or were quarantined. */
    blocked: number;
    /** Unconditional required documents with no ready file. */
    missing_required: number;
    /** The same problems by name, when the page has them. */
    problems?: Problem[];
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

export const REASON_MIN = 20;
export const REASON_MAX = 2000;

export interface Actions {
    start: boolean;
    accept: boolean;
    reject: boolean;
    /** Another officer holds it: nothing but an explicit take-over. */
    takeOver: boolean;
}

const NONE: Actions = {
    start: false,
    accept: false,
    reject: false,
    takeOver: false,
};

/**
 * The transitions the status machine has for this deposit, for this
 * officer. `submitted` can only be opened; a decision needs it open first.
 */
export function availableActions(
    status: string,
    holder: Holder | null,
    canDecide: boolean,
): Actions {
    if (!canDecide) {
        return NONE;
    }

    if (status === 'submitted') {
        return { ...NONE, start: true };
    }

    if (status !== 'under_review') {
        return NONE;
    }

    if (holder !== null && !holder.is_you) {
        return { ...NONE, takeOver: true };
    }

    return { ...NONE, accept: true, reject: true };
}

export type BlockerKey =
    'noFiles' | 'pendingFiles' | 'blockedFiles' | 'missingRequired';

export interface Blocker {
    key: BlockerKey;
    count: number;
}

/** Why "Accepter" is disabled — empty when it is not. */
export function acceptBlockers(checks: Checks): Blocker[] {
    const blockers: Blocker[] = [];

    if (checks.files === 0) {
        blockers.push({ key: 'noFiles', count: 0 });
    }

    if (checks.pending > 0) {
        blockers.push({ key: 'pendingFiles', count: checks.pending });
    }

    if (checks.blocked > 0) {
        blockers.push({ key: 'blockedFiles', count: checks.blocked });
    }

    if (checks.missing_required > 0) {
        blockers.push({
            key: 'missingRequired',
            count: checks.missing_required,
        });
    }

    return blockers;
}

/** The server's problem list as the same counts the checklist shows. */
export function checksFromProblems(problems: Problem[], files: number): Checks {
    const count = (...codes: string[]): number =>
        problems.filter((problem) => codes.includes(problem.code)).length;

    return {
        files: count('no_files') > 0 ? 0 : files,
        pending: count('file_in_pipeline'),
        blocked: count('file_failed', 'file_quarantined'),
        missing_required: count('slot_empty'),
        problems,
    };
}

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

function xsrfToken(): string | null {
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
