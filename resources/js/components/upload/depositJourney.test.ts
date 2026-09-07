import { describe, expect, it } from 'vitest';
import type { MediaFileSummary, UploadFileState } from '@/types/upload';
import { mergeDepositEntries } from './depositJourney';

function makeUploadFile(
    overrides: Partial<UploadFileState> = {},
): UploadFileState {
    return {
        id: 'client-1',
        workId: 1,
        file: null,
        filename: 'reel.mp4',
        size: 1_000_000,
        mime: 'video/mp4',
        extension: 'mp4',
        sessionUuid: 'session-1',
        chunkSize: 8_388_608,
        totalChunks: 1,
        chunks: [],
        status: 'uploading',
        errorCode: null,
        errorChunkIndex: null,
        remainingQuotaBytes: null,
        bytesUploaded: 500_000,
        speedBps: 100_000,
        etaSeconds: 5,
        expiresAt: null,
        mediaFileUuid: null,
        needsFileReselect: false,
        ...overrides,
    };
}

function makeMediaFile(
    overrides: Partial<MediaFileSummary> = {},
): MediaFileSummary {
    return {
        uuid: 'media-1',
        original_name: 'reel.mp4',
        extension: 'mp4',
        mime: 'video/mp4',
        size_bytes: 1_000_000,
        status: 'ready',
        duration_sec: null,
        width: null,
        height: null,
        sha256_plain: null,
        variant_count: 0,
        created_at: '2026-09-06T00:00:00.000000Z',
        ...overrides,
    };
}

describe('mergeDepositEntries', () => {
    /**
     * The regression the incident recording exposes: a 2.4 GB upload runs
     * for hours before a `MediaFile` row exists, so an empty state gated
     * on `mediaFiles.length === 0` alone hides the file for that entire
     * time. The page must merge in-flight uploads for this work into the
     * same list it renders `mediaFiles` from, so a file is visible the
     * moment it's selected — long before `mediaFiles` has anything in it.
     */
    it('includes an in-flight upload while mediaFiles is still empty', () => {
        const uploading = makeUploadFile({ workId: 42, status: 'uploading' });

        const entries = mergeDepositEntries([uploading], 42, [], new Set());

        expect(entries).toHaveLength(1);
        expect(entries[0]).toEqual({ kind: 'upload', file: uploading });
    });

    it('excludes a completed upload — its MediaFile row supersedes it', () => {
        const completed = makeUploadFile({ workId: 42, status: 'completed' });

        const entries = mergeDepositEntries([completed], 42, [], new Set());

        expect(entries).toHaveLength(0);
    });

    /**
     * Guards the exact class of bug flagged as a suspect during
     * diagnosis: `workId` must be compared as the same type on both
     * sides (`work.id`, a number) — a string/number mismatch (e.g. a
     * uuid compared against a numeric id) would silently exclude every
     * upload for this work with no error, reproducing the same kind of
     * silent failure from a different cause.
     */
    it('excludes an upload belonging to a different work', () => {
        const otherWork = makeUploadFile({ workId: 7, status: 'uploading' });

        const entries = mergeDepositEntries([otherWork], 42, [], new Set());

        expect(entries).toHaveLength(0);
    });

    it('marks a media file collapsed only if it was already ready at load', () => {
        const alreadyReady = makeMediaFile({ uuid: 'a', status: 'ready' });
        const justFinished = makeMediaFile({ uuid: 'b', status: 'ready' });

        const entries = mergeDepositEntries(
            [],
            42,
            [alreadyReady, justFinished],
            new Set(['a']),
        );

        expect(entries).toEqual([
            { kind: 'media', file: alreadyReady, collapsedReady: true },
            { kind: 'media', file: justFinished, collapsedReady: false },
        ]);
    });

    it('places in-flight uploads before media rows', () => {
        const uploading = makeUploadFile({ workId: 1 });
        const ready = makeMediaFile();

        const entries = mergeDepositEntries([uploading], 1, [ready], new Set());

        expect(entries.map((e) => e.kind)).toEqual(['upload', 'media']);
    });
});
