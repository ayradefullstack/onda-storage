/**
 * Maps the two independent state machines this feature displays — the
 * client-side `UploadFileState` (queued through completing) and the
 * server-side `MediaFileSummary` (scanning through ready) — onto one
 * continuous "custody rail" so a file's row never disappears and
 * reappears as it crosses from one to the other. Kept out of the store
 * and out of `types/upload.ts` deliberately: this is presentation-only
 * derivation, not state either of those owns.
 */
import type { MediaFileSummary, UploadFileState } from '@/types/upload';

/**
 * Merges the client-tracked in-flight uploads for one work with its
 * server-tracked `MediaFile` rows into a single ordered list, so a file
 * never disappears from the page between "still uploading" and "has a
 * completed row" — the structural gap the redesign exists to close.
 * `completed` uploads are excluded: the page's own watcher reloads
 * `mediaFiles` the moment that status is reached, so the row is picked up
 * there instead within a moment rather than being shown twice.
 */
export function mergeDepositEntries(
    uploadFiles: UploadFileState[],
    workId: number,
    mediaFiles: MediaFileSummary[],
    readyAtLoadUuids: ReadonlySet<string>,
): DepositEntry[] {
    const uploadEntries: UploadEntry[] = uploadFiles
        .filter((file) => file.workId === workId && file.status !== 'completed')
        .map((file) => ({ kind: 'upload', file }));

    const mediaEntries: MediaEntry[] = mediaFiles.map((file) => ({
        kind: 'media',
        file,
        collapsedReady:
            file.status === 'ready' && readyAtLoadUuids.has(file.uuid),
    }));

    return [...uploadEntries, ...mediaEntries];
}

export interface UploadEntry {
    kind: 'upload';
    file: UploadFileState;
}

export interface MediaEntry {
    kind: 'media';
    file: MediaFileSummary;
    /**
     * True when this file was already `ready` the moment the page loaded —
     * as opposed to reaching `ready` during the current visit. Only the
     * former collapses to the seal-only view; a deposit the author is
     * actively watching finish stays on the full rail.
     */
    collapsedReady: boolean;
}

export type DepositEntry = UploadEntry | MediaEntry;

/** 0-indexed position on the 6-step rail: queued…uploading…finishing…checking…preparing…deposited. */
export const RAIL_LENGTH = 6;

export function entryKey(entry: DepositEntry): string {
    return entry.kind === 'upload'
        ? `u:${entry.file.id}`
        : `m:${entry.file.uuid}`;
}

export function filenameOf(entry: DepositEntry): string {
    return entry.kind === 'upload'
        ? entry.file.filename
        : entry.file.original_name;
}

export type AlertTone = 'error' | 'quarantine';

/**
 * `failed` / `expired` / `quota_exceeded` and a media-side `failed` /
 * `quarantined` all break off the rail into their own block rather than
 * rendering as a colored rail segment — an error is a thing to read and
 * act on, not a rail decoration. `paused` stays on the rail: it's a safe,
 * author-initiated stop, not a failure.
 */
export function alertToneOf(entry: DepositEntry): AlertTone | null {
    if (entry.kind === 'upload') {
        return entry.file.status === 'failed' ||
            entry.file.status === 'expired' ||
            entry.file.status === 'quota_exceeded'
            ? 'error'
            : null;
    }

    if (entry.file.status === 'failed') {
        return 'error';
    }

    if (entry.file.status === 'quarantined') {
        return 'quarantine';
    }

    return null;
}

export function railStepIndex(entry: DepositEntry): number {
    if (entry.kind === 'upload') {
        switch (entry.file.status) {
            case 'queued':
            case 'initializing':
                return 0;
            case 'completing':
                return 2;
            case 'uploading':
            case 'paused':
            default:
                return 1;
        }
    }

    switch (entry.file.status) {
        case 'assembling':
            return 2;
        case 'processing':
            return 4;
        case 'ready':
            return 5;
        case 'scanning':
        default:
            return 3;
    }
}

export type RailTone = 'active' | 'paused';

export function railToneOf(entry: DepositEntry): RailTone {
    return entry.kind === 'upload' && entry.file.status === 'paused'
        ? 'paused'
        : 'active';
}

/**
 * The support line can only resolve a reference that exists in the
 * database — a client-generated `UploadFileState.id` never reaches the
 * backend as a lookup key, so a pre-completion failure gets no reference.
 * Only a `MediaFile` row (created at `complete()`, uuid is its route key)
 * failing or being quarantined during P5 processing has one.
 */
export function referenceFor(entry: DepositEntry): string | null {
    if (
        entry.kind === 'media' &&
        (entry.file.status === 'failed' || entry.file.status === 'quarantined')
    ) {
        return entry.file.uuid.slice(0, 8);
    }

    return null;
}

/**
 * A rolling ETA computed from one or two chunks swings wildly (e.g. "14h"
 * then "2h" a minute later), which teaches the author not to trust the
 * number. Hold the display back until a handful of chunks (or all of them,
 * for a small file) have actually landed.
 */
export function etaEligible(file: UploadFileState): boolean {
    if (file.status !== 'uploading' || file.speedBps <= 0) {
        return false;
    }

    const doneChunks = file.chunks.reduce(
        (count, chunk) => (chunk.status === 'done' ? count + 1 : count),
        0,
    );
    const threshold = Math.min(5, file.totalChunks || 5);

    return doneChunks >= threshold;
}

/**
 * Smooths the *displayed* ETA on top of the store's already-smoothed speed:
 * once shown, a swing of more than ~half the current estimate is damped
 * rather than snapped to, so the number doesn't visibly jump around.
 * State lives in the closure the caller holds (one per mounted card) —
 * intentionally not in the store, since this is a display concern only.
 */
export function createEtaSmoother(): (raw: number | null) => number | null {
    let displayed: number | null = null;

    return (raw: number | null): number | null => {
        if (raw === null) {
            displayed = null;

            return null;
        }

        if (displayed === null || displayed <= 0) {
            displayed = raw;

            return displayed;
        }

        const diffRatio = Math.abs(raw - displayed) / displayed;
        displayed = diffRatio > 0.5 ? displayed + (raw - displayed) * 0.5 : raw;

        return displayed;
    };
}
