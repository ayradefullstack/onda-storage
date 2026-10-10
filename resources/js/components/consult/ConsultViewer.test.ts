import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { renderToString } from '@vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, h } from 'vue';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import type {
    ConsultationDescriptor,
    PreviewFamily,
} from '@/types/consultation';
import ConsultViewer from './ConsultViewer.vue';

vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response('', { status: 500 })),
);

function descriptor(
    over: Partial<ConsultationDescriptor> = {},
): ConsultationDescriptor {
    return {
        family: 'pdf',
        status: 'ready',
        reason: null,
        notice: null,
        assets: [],
        pageCount: null,
        watermark: 'admin@onda.dz',
        meta: {
            filename: 'acte-de-naissance.pdf',
            sizeBytes: 2048,
            mime: 'application/pdf',
            sha256: 'ab'.repeat(32),
            depositedAt: '2026-10-01T10:00:00Z',
            slotLabel: 'Justificatif',
        },
        actions: { download: false },
        ...over,
    };
}

async function render(d: ConsultationDescriptor | null, compact = false) {
    const i18n = createI18n({
        legacy: false,
        locale: 'en',
        messages: { en },
        missingWarn: false,
        fallbackWarn: false,
    });

    const app = createSSRApp({
        render: () =>
            h(ConsultViewer, { descriptor: d, fileKey: 'f1', compact }),
    });
    app.use(i18n);

    return renderToString(app);
}

describe('ConsultViewer states', () => {
    it('shows a spinner while no descriptor has arrived', async () => {
        const html = await render(null);

        expect(html).toContain('data-status="loading"');
    });

    it('shows pending with the preparing message', async () => {
        const html = await render(
            descriptor({ status: 'pending', family: 'pdf' }),
        );

        expect(html).toContain('data-state="pending"');
        expect(html).toContain('Preparing the preview');
    });

    it('states the exact reason for an unsupported file and exposes no media', async () => {
        const html = await render(
            descriptor({
                status: 'unsupported',
                family: 'document',
                reason: 'tool_missing:soffice',
            }),
        );

        expect(html).toContain('LibreOffice is not installed on this server.');
        expect(html).not.toContain('<img');
        expect(html).not.toContain('<video');
        expect(html).not.toContain('<audio');
        expect(html).not.toContain('<a ');
    });

    it('states the reason for a failed file', async () => {
        const html = await render(
            descriptor({
                status: 'failed',
                reason: 'render_timeout',
            }),
        );

        expect(html).toContain('Generating the preview took too long.');
    });

    it('never offers a download', async () => {
        const html = await render(
            descriptor({ status: 'unsupported', reason: 'unsupported_format' }),
        );

        expect(html.toLowerCase()).not.toContain('download');
    });
});

describe('ConsultViewer compact vs full', () => {
    const unsupported = descriptor({
        status: 'unsupported',
        reason: 'unsupported_format',
    });

    it('shows the metadata block in full mode', async () => {
        expect(await render(unsupported, false)).toContain(
            'acte-de-naissance.pdf',
        );
    });

    it('trims the metadata block in compact mode', async () => {
        expect(await render(unsupported, true)).not.toContain(
            'acte-de-naissance.pdf',
        );
    });
});

describe('ConsultViewer picks its body from the family', () => {
    const asset = (kind: string, pageIndex: number | null, url: string) => ({
        kind,
        pageIndex,
        url,
    });

    it('renders page images for a pdf, lazily and not draggable', async () => {
        const html = await render(
            descriptor({
                family: 'pdf',
                pageCount: 2,
                assets: [asset('page', 0, '/p/0'), asset('page', 1, '/p/1')],
            }),
        );

        expect(html.match(/<img/g)).toHaveLength(2);
        expect(html).toContain('loading="lazy"');
        expect(html).toContain('draggable="false"');
        expect(html).toContain('data-testid="watermark"');
    });

    it('uses the same page viewer for a converted document', async () => {
        const html = await render(
            descriptor({
                family: 'presentation',
                assets: [asset('page', 0, '/p/0')],
            }),
        );

        expect(html).toContain('data-testid="page-scroller"');
    });

    it('locks down a video player', async () => {
        const html = await render(
            descriptor({
                family: 'video',
                assets: [
                    asset('video', 0, '/v'),
                    asset('poster', 0, '/poster'),
                ],
            }),
        );

        expect(html).toContain('controlslist="nodownload noplaybackrate"');
        expect(html).toContain('disablepictureinpicture');
        expect(html).toContain('poster="/poster"');
    });

    it('locks down an audio player and shows the waveform', async () => {
        const html = await render(
            descriptor({
                family: 'audio',
                assets: [asset('audio', 0, '/a'), asset('waveform', 0, '/w')],
            }),
        );

        expect(html).toContain('controlslist="nodownload noplaybackrate"');
        expect(html).toContain('src="/w"');
    });

    it('turns the watermark off when the server sends none', async () => {
        const html = await render(
            descriptor({
                family: 'pdf',
                watermark: null,
                assets: [asset('page', 0, '/p/0')],
            }),
        );

        expect(html).not.toContain('data-testid="watermark"');
    });

    it('falls back to the card for a family without a body', async () => {
        const html = await render(
            descriptor({ family: 'other' as PreviewFamily }),
        );

        expect(html).toContain('data-state="ready"');
    });
});

function sourceFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            return sourceFiles(path);
        }

        return /\.(vue|ts)$/.test(name) && !name.endsWith('.test.ts')
            ? [path]
            : [];
    });
}

describe('static guarantees', () => {
    const files = [
        ...sourceFiles(join(__dirname)),
        join(__dirname, '../../pages/admin/oeuvres/FileReview.vue'),
        join(__dirname, '../../pages/admin/oeuvres/Show.vue'),
    ];

    it.each(files.map((f) => [f.split(/[\\/]resources[\\/]js[\\/]/)[1], f]))(
        '%s has no v-html, iframe, embed or object',
        (_name, file) => {
            // Comments may *mention* these (to say why they are absent).
            const source = readFileSync(file, 'utf8')
                .replace(/\/\*[\s\S]*?\*\//g, '')
                .replace(/<!--[\s\S]*?-->/g, '');

            expect(source).not.toMatch(/v-html/);
            expect(source).not.toMatch(/<(iframe|embed|object)[\s>]/i);
        },
    );
});
