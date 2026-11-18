import { renderToString } from '@vue/server-renderer';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createSSRApp, effectScope, h, ref } from 'vue';
import { createI18n } from 'vue-i18n';
import {
    alertToneOf,
    mergeDepositEntries,
    railStepIndex,
} from '@/components/upload/depositJourney';
import FileStatusPollNotice from '@/components/upload/FileStatusPollNotice.vue';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';
import type { MediaFileStatus, MediaFileSummary } from '@/types/upload';
import type { LiveFileStatus, PollPhase } from './useFileStatusPoll';
import {
    nextPollDelay,
    SLOW_AFTER_MS,
    STALLED_AFTER_MS,
    useFileStatusPoll,
} from './useFileStatusPoll';

/** What the status endpoint returns for one file. */
const STAGE: Record<MediaFileStatus, string | null> = {
    uploading: 'upload',
    assembling: 'assembly',
    scanning: 'antivirus',
    processing: 'processing',
    ready: 'sealed',
    failed: null,
    quarantined: null,
};
const ERROR: Partial<Record<MediaFileStatus, string>> = {
    failed: 'media.failedWithRef',
    quarantined: 'media.quarantineWithRef',
};

const reply = (
    ...files: [string, MediaFileStatus][]
): { files: LiveFileStatus[] } => ({
    files: files.map(([id, status]) => ({
        id,
        status,
        stage: STAGE[status],
        error: ERROR[status] ?? null,
    })),
});

function media(uuid: string, status: MediaFileStatus): MediaFileSummary {
    return {
        uuid,
        can_remove: false,
        college_oeuvre_file_id: null,
        original_name: `${uuid}.pdf`,
        extension: 'pdf',
        mime: 'application/pdf',
        size_bytes: 9_016_380,
        status,
        duration_sec: null,
        width: null,
        height: null,
        sha256_plain: null,
        variant_count: 0,
        created_at: '2026-10-14T20:00:00+00:00',
    } as MediaFileSummary;
}

/** A document whose visibility the test controls. */
function fakeDocument() {
    const listeners = new Set<() => void>();

    return {
        visibilityState: 'visible' as DocumentVisibilityState,
        addEventListener: vi.fn((_: string, listener: () => void) =>
            listeners.add(listener),
        ),
        removeEventListener: vi.fn((_: string, listener: () => void) =>
            listeners.delete(listener),
        ),
        set(state: DocumentVisibilityState) {
            this.visibilityState = state;
            listeners.forEach((listener) => listener());
        },
        get listenerCount() {
            return listeners.size;
        },
    };
}

/**
 * Mounts the composable in its own effect scope, with a server whose replies
 * the test queues; the last reply repeats once the queue is empty.
 */
function setup(
    initial: MediaFileSummary[],
    replies: { files: LiveFileStatus[] }[],
) {
    const files = ref<MediaFileSummary[]>(initial);
    const doc = fakeDocument();
    const onSettled = vi.fn();
    const queue = [...replies];
    const fetcher = vi.fn(async () =>
        queue.length > 1 ? queue.shift()! : queue[0],
    );
    const scope = effectScope();
    const poll = scope.run(() =>
        useFileStatusPoll({
            url: () => '/author/oeuvres/o-1/files/status',
            files: () => files.value,
            onSettled,
            fetcher,
            document: doc as unknown as Document,
        }),
    )!;

    return { files, doc, onSettled, fetcher, scope, poll };
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useFileStatusPoll', () => {
    // The root cause: the page armed its poll only on mount. An oeuvre with
    // nothing in flight at mount never polled, so a file uploaded afterwards
    // stayed on step 4 ("Antivirus") until F5.
    it('starts when a file appears after mount, and follows it past step 4 to the seal', async () => {
        const { files, poll, fetcher, onSettled } = setup(
            [],
            [reply(['a', 'scanning']), reply(['a', 'ready'])],
        );

        expect(poll.running.value).toBe(false);

        // The reload that follows the upload's `complete` brings the row in.
        files.value = [media('a', 'scanning')];
        await vi.advanceTimersByTimeAsync(0);
        expect(poll.running.value).toBe(true);

        const rail = () =>
            railStepIndex(
                mergeDepositEntries(
                    [],
                    1,
                    files.value,
                    new Set(),
                    poll.live.value,
                )[0],
            );
        expect(rail()).toBe(3);

        await vi.advanceTimersByTimeAsync(1500);
        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(rail()).toBe(3);

        await vi.advanceTimersByTimeAsync(3000);
        expect(fetcher).toHaveBeenCalledTimes(2);
        // The props still say `scanning`; the live status moves the rail.
        expect(files.value[0].status).toBe('scanning');
        expect(rail()).toBe(5);
        expect(onSettled).toHaveBeenCalledTimes(1);
        expect(poll.running.value).toBe(false);
    });

    it('starts on load when a file is already processing (a refresh mid-pipeline)', async () => {
        const { poll, fetcher } = setup(
            [media('a', 'ready'), media('b', 'scanning')],
            [reply(['a', 'ready'], ['b', 'scanning'])],
        );

        expect(poll.running.value).toBe(true);
        await vi.advanceTimersByTimeAsync(1500);
        expect(fetcher).toHaveBeenCalledTimes(1);
    });

    it('stays idle when every file is already terminal', async () => {
        const { poll, fetcher } = setup(
            [media('a', 'ready'), media('b', 'failed')],
            [reply()],
        );

        await vi.advanceTimersByTimeAsync(60_000);
        expect(poll.running.value).toBe(false);
        expect(fetcher).not.toHaveBeenCalled();
    });

    it('backs off 1.5 s → 3 s → 5 s, and never gives up after 3 and 15 minutes', async () => {
        const { poll, fetcher } = setup(
            [media('a', 'scanning')],
            [reply(['a', 'scanning'])],
        );
        const start = Date.now();
        const times: number[] = [];
        fetcher.mockImplementation(async () => {
            times.push(Date.now() - start);

            return reply(['a', 'scanning']);
        });

        await vi.advanceTimersByTimeAsync(20_000);
        expect(times.slice(0, 5)).toEqual([1500, 4500, 9500, 14500, 19500]);
        expect(poll.phase.value).toBe('active');

        await vi.advanceTimersByTimeAsync(SLOW_AFTER_MS);
        expect(poll.phase.value).toBe('slow');
        expect(poll.running.value).toBe(true);
        const atSlow = times.length;
        await vi.advanceTimersByTimeAsync(10_000);
        expect(times.length - atSlow).toBe(1);

        await vi.advanceTimersByTimeAsync(STALLED_AFTER_MS);
        expect(poll.phase.value).toBe('stalled');
        expect(poll.running.value).toBe(true);
        const atStalled = times.length;
        await vi.advanceTimersByTimeAsync(30_000);
        expect(times.length - atStalled).toBe(1);

        // An hour in: still alive, at 30 s.
        await vi.advanceTimersByTimeAsync(60 * 60_000);
        expect(poll.running.value).toBe(true);
        expect(times.at(-1)! - times.at(-2)!).toBe(30_000);
    });

    it('the delay schedule itself', () => {
        expect([0, 1, 2, 3, 9].map((n) => nextPollDelay(n, 0))).toEqual([
            1500, 3000, 5000, 5000, 5000,
        ]);
        expect(nextPollDelay(40, SLOW_AFTER_MS)).toBe(10_000);
        expect(nextPollDelay(40, STALLED_AFTER_MS)).toBe(30_000);
    });

    it.each([
        ['ready', null],
        ['failed', 'error'],
        ['quarantined', 'quarantine'],
    ] as const)(
        'a %s result stops the loop and shows its state at once',
        async (terminal, tone) => {
            const { files, poll, onSettled, fetcher } = setup(
                [media('a', 'scanning')],
                [reply(['a', terminal])],
            );

            await vi.advanceTimersByTimeAsync(1500);
            expect(poll.running.value).toBe(false);
            expect(onSettled).toHaveBeenCalledTimes(1);

            const entry = mergeDepositEntries(
                [],
                1,
                files.value,
                new Set(),
                poll.live.value,
            )[0];
            expect(entry.kind === 'media' && entry.file.status).toBe(terminal);
            expect(alertToneOf(entry)).toBe(tone);

            await vi.advanceTimersByTimeAsync(60_000);
            expect(fetcher).toHaveBeenCalledTimes(1);
        },
    );

    it('a network error is not terminal: it polls again', async () => {
        const { poll, fetcher } = setup(
            [media('a', 'scanning')],
            [reply(['a', 'ready'])],
        );
        fetcher.mockRejectedValueOnce(new Error('offline'));

        await vi.advanceTimersByTimeAsync(1500);
        expect(poll.running.value).toBe(true);
        await vi.advanceTimersByTimeAsync(3000);
        expect(fetcher).toHaveBeenCalledTimes(2);
        expect(poll.running.value).toBe(false);
    });

    it('pauses while the tab is hidden, and fetches at once when it is shown', async () => {
        const { doc, poll, fetcher } = setup(
            [media('a', 'scanning')],
            [reply(['a', 'scanning'])],
        );

        await vi.advanceTimersByTimeAsync(1500);
        expect(fetcher).toHaveBeenCalledTimes(1);

        doc.set('hidden');
        await vi.advanceTimersByTimeAsync(10 * 60_000);
        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(poll.running.value).toBe(true);

        doc.set('visible');
        await vi.advanceTimersByTimeAsync(0);
        expect(fetcher).toHaveBeenCalledTimes(2);

        // …and the loop carries on.
        await vi.advanceTimersByTimeAsync(10_000);
        expect(fetcher.mock.calls.length).toBeGreaterThan(2);
    });

    it('calls onSettled exactly once per terminal transition, not per poll', async () => {
        const { files, onSettled, poll } = setup(
            [media('a', 'scanning'), media('b', 'scanning')],
            [
                reply(['a', 'scanning'], ['b', 'scanning']),
                reply(['a', 'ready'], ['b', 'scanning']),
                reply(['a', 'ready'], ['b', 'scanning']),
                reply(['a', 'ready'], ['b', 'ready']),
            ],
        );

        await vi.advanceTimersByTimeAsync(1500); // both scanning
        expect(onSettled).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(3000); // a → ready
        expect(onSettled).toHaveBeenCalledTimes(1);

        // The page's reload lands: its props now say `ready` for a.
        files.value = [media('a', 'ready'), media('b', 'scanning')];
        await vi.advanceTimersByTimeAsync(5000); // a still ready: no reload
        expect(onSettled).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(5000); // b → ready
        expect(onSettled).toHaveBeenCalledTimes(2);
        expect(poll.running.value).toBe(false);
    });

    it('a file that completes and finishes before the props know it still triggers the reload', async () => {
        const { poll, onSettled } = setup([], [reply(['new', 'ready'])]);

        poll.kick();
        await vi.advanceTimersByTimeAsync(1500);
        expect(onSettled).toHaveBeenCalledTimes(1);
        expect(poll.running.value).toBe(false);
    });

    it('clears its timer and listener on unmount', async () => {
        const { scope, doc, fetcher } = setup(
            [media('a', 'scanning')],
            [reply(['a', 'scanning'])],
        );

        await vi.advanceTimersByTimeAsync(1500);
        expect(vi.getTimerCount()).toBe(1);

        scope.stop();
        expect(vi.getTimerCount()).toBe(0);
        expect(doc.listenerCount).toBe(0);

        await vi.advanceTimersByTimeAsync(60_000);
        expect(fetcher).toHaveBeenCalledTimes(1);
    });
});

describe('FileStatusPollNotice', () => {
    async function render(phase: PollPhase, locale = 'fr'): Promise<string> {
        const app = createSSRApp({
            render: () => h(FileStatusPollNotice, { phase }),
        });
        app.use(
            createI18n({
                legacy: false,
                locale,
                messages: { fr, ar, en },
            }),
        );

        return renderToString(app);
    }

    it('says nothing while processing is on time', async () => {
        expect(await render('active')).not.toContain('poll-slow');
        expect(await render('idle')).not.toContain('poll-slow');
    });

    it('shows the hint after 3 minutes, and the refresh button after 15', async () => {
        const slow = await render('slow');
        expect(slow).toContain(fr['upload.poll.slow']);
        expect(slow).not.toContain('poll-refresh');

        const stalled = await render('stalled');
        expect(stalled).toContain('poll-refresh');
        expect(stalled).toContain(fr['upload.poll.refresh']);
    });

    it('is translated in Arabic and English', async () => {
        expect(await render('stalled', 'ar')).toContain(
            ar['upload.poll.refresh'],
        );
        expect(await render('slow', 'en')).toContain(en['upload.poll.slow']);
    });
});
