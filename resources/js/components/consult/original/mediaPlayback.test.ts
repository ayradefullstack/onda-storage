import { renderToString } from '@vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, h, ref } from 'vue';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import { classifyMediaFailure, createResumePoint } from './mediaPlayback';
import MediaUnreadableCard from './MediaUnreadableCard.vue';
import { useOriginalMedia } from './useOriginalMedia';

const status = (code: number) =>
    vi.fn(
        async () => new Response('', { status: code }),
    ) as unknown as typeof fetch;

function fakePlayer(currentTime: number) {
    return {
        currentTime,
        play: vi.fn(async () => undefined),
    } as unknown as HTMLMediaElement;
}

describe('media error classification', () => {
    it('probes with a 1-byte Range and reads 403 as an expired URL', async () => {
        const fetchFn = status(403);

        expect(await classifyMediaFailure('/x', fetchFn)).toBe('expired');
        expect(fetchFn).toHaveBeenCalledWith(
            '/x',
            expect.objectContaining({ headers: { Range: 'bytes=0-0' } }),
        );
    });

    it('reads a served file as an undecodable format', async () => {
        expect(await classifyMediaFailure('/x', status(206))).toBe(
            'unsupported',
        );
    });
});

describe('a video error event', () => {
    it('renders the "format not readable" card and never a download', async () => {
        const player = ref<HTMLMediaElement | null>(fakePlayer(0));
        const onExpired = vi.fn();
        const media = useOriginalMedia(
            player,
            () => '/v',
            onExpired,
            status(206),
        );

        await media.onError();

        expect(media.unreadable.value).toBe(true);
        expect(onExpired).not.toHaveBeenCalled();

        const i18n = createI18n({
            legacy: false,
            locale: 'en',
            messages: { en },
        });
        const app = createSSRApp({
            render: () =>
                h(MediaUnreadableCard, {
                    kind: 'video',
                    mime: 'video/quicktime',
                }),
        });
        app.use(i18n);
        const html = await renderToString(app);

        expect(html).toContain('data-testid="media-unreadable"');
        expect(html).toContain(en['consult.original.videoUnreadable']);
        expect(html).toContain('video/quicktime');
        expect(html).not.toMatch(/download|<a /i);
    });
});

describe('a 403 mid-playback', () => {
    it('asks for fresh URLs and resumes at the same second once reloaded', async () => {
        const element = fakePlayer(73.5);
        const player = ref<HTMLMediaElement | null>(element);
        const onExpired = vi.fn();
        const media = useOriginalMedia(
            player,
            () => '/v',
            onExpired,
            status(403),
        );

        await media.onError();

        expect(onExpired).toHaveBeenCalledTimes(1);
        expect(media.unreadable.value).toBe(false);

        // The element reloads with the refreshed URL: position restarts at 0.
        element.currentTime = 0;
        media.onLoadedMetadata();

        expect(element.currentTime).toBe(73.5);
        expect(element.play).toHaveBeenCalled();

        // Resume is consumed once.
        element.currentTime = 0;
        media.onLoadedMetadata();
        expect(element.currentTime).toBe(0);
    });

    it('remembers nothing for a position of zero', () => {
        const resume = createResumePoint();
        resume.remember(0);

        expect(resume.take()).toBe(0);
    });
});
