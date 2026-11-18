/**
 * The author's side of per-file review: while a deposit is under review,
 * a file the ONDA rejected may be removed, and its slot then takes one
 * replacement. The server decides every part of this (`can_remove` per
 * file, `replacement.open` per slot); these helpers only read its answers.
 */
import { xsrfToken } from '@/components/review/decisions';

/** OeuvreController::replacementState, as the page receives it. */
export interface ReplacementState {
    /** Slot id → replacements it still takes. `[]` when none is open. */
    open: Record<string, number> | [];
    /** Replacement uploads in flight, each holding one removed file. */
    uploading: number;
    /** Rejected files still on the deposit. */
    rejected: number;
    /** Replacements waiting for the ONDA's decision. */
    pending: number;
}

export const EMPTY_REPLACEMENT: ReplacementState = {
    open: [],
    uploading: 0,
    rejected: 0,
    pending: 0,
};

/** How many replacements a slot still takes (0 = no dropzone). */
export function openFor(
    state: ReplacementState | null | undefined,
    slotId: number,
): number {
    if (!state || Array.isArray(state.open)) {
        return 0;
    }

    return state.open[String(slotId)] ?? 0;
}

/** Files the author still has to deal with: rejected, or removed and open. */
export function toReplaceCount(
    state: ReplacementState | null | undefined,
): number {
    if (!state) {
        return 0;
    }

    const open = Array.isArray(state.open)
        ? 0
        : Object.values(state.open).reduce((sum, n) => sum + n, 0);

    return state.rejected + open;
}

export type RemovalOutcome =
    | { kind: 'removed' }
    /** 409: the ONDA has just revised the decision; the file stays. */
    | { kind: 'noLongerRejected' }
    /** 409: the deposit was registered or rejected meanwhile. */
    | { kind: 'decided' }
    /** 422 (or 403): the server's own message. */
    | { kind: 'invalid'; message: string | null }
    | { kind: 'failed' };

/** DELETE one rejected file, as JSON so a race can be told apart. Never throws. */
export async function removeRejectedFile(url: string): Promise<RemovalOutcome> {
    let response: Response;

    try {
        const token = xsrfToken();

        response = await fetch(url, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token === null ? {} : { 'X-XSRF-TOKEN': token }),
            },
        });
    } catch {
        return { kind: 'failed' };
    }

    let payload: Record<string, unknown> = {};

    try {
        payload = (await response.json()) as Record<string, unknown>;
    } catch {
        // A non-JSON body is handled by its status.
    }

    if (response.ok) {
        return { kind: 'removed' };
    }

    if (response.status === 409) {
        return payload.error === 'file_no_longer_rejected'
            ? { kind: 'noLongerRejected' }
            : { kind: 'decided' };
    }

    if (response.status === 422 || response.status === 403) {
        const errors = payload.errors as Record<string, string[]> | undefined;
        const first = errors ? Object.values(errors)[0]?.[0] : undefined;

        return {
            kind: 'invalid',
            message:
                first ??
                (typeof payload.message === 'string' ? payload.message : null),
        };
    }

    return { kind: 'failed' };
}

export interface RemovalFlowDeps {
    /** The DELETE url of a file. */
    url: (uuid: string) => string;
    /** The slot a file sits in (null: none, so nothing to focus). */
    slotOf: (uuid: string) => number | null;
    /**
     * A silent reload: `only` the given props (undefined = the whole page,
     * after the deposit itself changed), then `after`.
     */
    reload: (only: string[] | undefined, after: () => void) => void;
    /** The props that follow a file's removal. */
    reloadProps: readonly string[];
    /** Focus the slot's "Remplacement attendu" dropzone. */
    focusSlot: (slotId: number) => void;
    toast: {
        success: (message: string) => void;
        info: (message: string) => void;
        error: (message: string) => void;
    };
    t: (key: string) => string;
}

/**
 * The page's "Remplacer" / "Supprimer" flow: DELETE the rejected file as
 * JSON, say what happened, reload only what changed, and — for "Remplacer"
 * — hand the slot's dropzone the focus once the reload has rendered it.
 * A race is told apart: the ONDA has just revised the decision (the file
 * stays), or decided the whole deposit (the page reloads into its new
 * state). One removal at a time; `busy` is the file in flight.
 */
export function createRemovalFlow(deps: RemovalFlowDeps): {
    busy: () => string | null;
    remove: (uuid: string, replace: boolean) => Promise<RemovalOutcome | null>;
    onBusyChange: (listener: (uuid: string | null) => void) => void;
} {
    let busy: string | null = null;
    let listener: (uuid: string | null) => void = () => undefined;

    const setBusy = (uuid: string | null): void => {
        busy = uuid;
        listener(uuid);
    };

    async function remove(
        uuid: string,
        replace: boolean,
    ): Promise<RemovalOutcome | null> {
        if (busy !== null) {
            return null;
        }

        const slotId = deps.slotOf(uuid);
        setBusy(uuid);
        const outcome = await removeRejectedFile(deps.url(uuid));
        const settle = (only: string[] | undefined, after?: () => void) =>
            deps.reload(only, () => {
                setBusy(null);
                after?.();
            });

        switch (outcome.kind) {
            case 'removed':
                deps.toast.success(
                    deps.t(
                        replace
                            ? 'oeuvres.replacement.toast.removedReplace'
                            : 'oeuvres.replacement.toast.removed',
                    ),
                );
                settle([...deps.reloadProps], () => {
                    if (replace && slotId !== null) {
                        deps.focusSlot(slotId);
                    }
                });
                break;
            case 'noLongerRejected':
                deps.toast.info(
                    deps.t('oeuvres.replacement.toast.noLongerRejected'),
                );
                settle([...deps.reloadProps]);
                break;
            case 'decided':
                deps.toast.info(deps.t('oeuvres.replacement.toast.decided'));
                settle(undefined);
                break;
            case 'invalid':
                deps.toast.error(
                    outcome.message ??
                        deps.t('oeuvres.replacement.toast.failed'),
                );
                settle([...deps.reloadProps]);
                break;
            default:
                setBusy(null);
                deps.toast.error(deps.t('oeuvres.replacement.toast.failed'));
        }

        return outcome;
    }

    return {
        busy: () => busy,
        remove,
        onBusyChange: (next) => {
            listener = next;
        },
    };
}

/**
 * Scroll a slot's replacement dropzone into view, focus its button, and
 * play one soft pulse on it — no smooth scroll and no pulse under reduced
 * motion. The zone may have been on the page already (a multi-file slot of
 * a deposit returned to its author), so the pulse is played here rather
 * than left to the zone's own first render.
 */
export function focusReplacementDropzone(
    slotId: number,
    reducedMotion: boolean,
): void {
    const zone = document.getElementById(replacementDropzoneId(slotId));

    if (!zone) {
        return;
    }

    zone.scrollIntoView?.({
        behavior: reducedMotion ? 'auto' : 'smooth',
        block: 'center',
    });
    zone.querySelector<HTMLButtonElement>('button')?.focus({
        preventScroll: true,
    });

    if (!reducedMotion) {
        zone.classList.add('motion-pulse-once');
        setTimeout(() => zone.classList.remove('motion-pulse-once'), 900);
    }
}

/**
 * "Aller au fichier": scroll a file row (or a slot's dropzone) into view,
 * focus it — the row itself when it is focusable, else its first button —
 * and play one soft pulse. No smooth scroll and no pulse under reduced
 * motion. Returns false when the target is not on the page.
 */
export function revealTarget(target: string, reducedMotion: boolean): boolean {
    const element = document.getElementById(target);

    if (!element) {
        return false;
    }

    element.scrollIntoView?.({
        behavior: reducedMotion ? 'auto' : 'smooth',
        block: 'center',
    });
    const focusable = element.hasAttribute('tabindex')
        ? element
        : element.querySelector<HTMLElement>('button');
    focusable?.focus({ preventScroll: true });

    if (!reducedMotion) {
        element.classList.add('motion-pulse-once');
        setTimeout(() => element.classList.remove('motion-pulse-once'), 900);
    }

    return true;
}

/** The anchor a notification links to: `#file-{uuid}`. */
export const fileAnchor = (uuid: string): string => `file-${uuid}`;

/** The id of a slot's "Remplacement attendu" dropzone. */
export const replacementDropzoneId = (slotId: number): string =>
    `replacement-slot-${slotId}`;
