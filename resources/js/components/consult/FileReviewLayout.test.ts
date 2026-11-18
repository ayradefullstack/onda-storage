import { renderToString } from '@vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, h, ref } from 'vue';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import type { ConsultationDescriptor } from '@/types/consultation';
import FileReviewLayout from './FileReviewLayout.vue';
import { FILE_PREVIEW } from './previewContext';
import { useSideViewer } from './useSideViewer';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router: { visit: vi.fn(), reload: vi.fn() },
}));

vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response('', { status: 500 })),
);

function descriptor(
    over: Partial<ConsultationDescriptor> = {},
): ConsultationDescriptor {
    return {
        family: 'text',
        mode: 'derivative',
        original: null,
        status: 'pending',
        reason: null,
        notice: null,
        assets: [],
        pageCount: null,
        watermark: null,
        meta: {
            filename: 'lettre.txt',
            sizeBytes: 2048,
            mime: 'text/plain',
            sha256: 'ab'.repeat(32),
            depositedAt: '2026-10-01T10:00:00Z',
            slotLabel: 'Justificatif',
        },
        actions: { download: false },
        ...over,
    };
}

async function render(details: boolean, d = descriptor()) {
    const app = createSSRApp({
        render: () =>
            h(FileReviewLayout, {
                oeuvre: { uuid: 'o1', title: 'Mon oeuvre', created_at: '' },
                file: {
                    uuid: 'f1',
                    original_name: 'lettre.txt',
                    extension: 'txt',
                    status: 'ready',
                },
                siblings: [
                    { uuid: 'f1', original_name: 'lettre.txt', slot: null },
                    { uuid: 'f2', original_name: 'autre.txt', slot: null },
                ],
                consultation: d,
                backHref: '/back',
                fileHref: (uuid: string) => `/file/${uuid}`,
                details,
            }),
    });
    app.use(
        createI18n({
            legacy: false,
            locale: 'en',
            messages: { en },
            missingWarn: false,
            fallbackWarn: false,
        }),
    );

    return renderToString(app);
}

describe('FileReviewLayout', () => {
    it('shows no tabs on the admin page and keeps the header facts', async () => {
        const html = await render(false);

        expect(html).not.toContain('role="tablist"');
        expect(html).toContain('SHA-256');
    });

    it('shows Preview and Details only on the author page, never an Activity tab', async () => {
        const html = await render(true);

        expect(html).toContain('role="tablist"');
        expect(html).toContain('>Preview<');
        expect(html).toContain('>Details<');
        expect(html.toLowerCase()).not.toContain('activity');
        // Details are not rendered until the tab is chosen.
        expect(html).not.toContain('data-testid="file-details"');
    });

    it('does not draw the watermark for the owner when the server sends none', async () => {
        const html = await render(
            true,
            descriptor({ status: 'ready', watermark: null }),
        );

        expect(html).not.toContain('data-testid="watermark"');
    });
});

describe('the side Viewer selection on the author page', () => {
    it('keeps the highlight and the viewer on the same file, dropping a slow reply', async () => {
        const replies = new Map<string, (d: ConsultationDescriptor) => void>();
        const load = (uuid: string) =>
            new Promise<ConsultationDescriptor | null>((resolve) =>
                replies.set(uuid, resolve),
            );

        const viewer = useSideViewer(null, load);
        const context = {
            oeuvreUuid: 'o1',
            selectedUuid: viewer.selectedUuid,
            open: viewer.select,
        };

        context.open('f1');
        context.open('f2');

        replies.get('f2')?.(
            descriptor({ meta: { ...descriptor().meta, filename: 'f2' } }),
        );
        await vi.waitFor(() =>
            expect(viewer.descriptor.value?.meta.filename).toBe('f2'),
        );
        replies.get('f1')?.(
            descriptor({ meta: { ...descriptor().meta, filename: 'f1' } }),
        );
        await Promise.resolve();

        expect(context.selectedUuid.value).toBe('f2');
        expect(viewer.descriptor.value?.meta.filename).toBe('f2');
    });

    it('exposes an injection key a deposit card can read', () => {
        expect(typeof FILE_PREVIEW).toBe('symbol');
        expect(ref(null).value).toBeNull();
    });
});
