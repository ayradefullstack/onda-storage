import { renderToString } from '@vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, h } from 'vue';
import type { Component } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: {
        props: ['href'],
        setup: (_props: unknown, { slots }: { slots: { default?: () => unknown } }) => () =>
            h('a', slots.default?.() as never),
    },
    router: { get: vi.fn() },
}));

vi.mock('@/routes/admin', () => ({ dashboard: () => ({ url: '/admin' }) }));
vi.mock('@/routes/admin/authors', () => ({
    show: (uuid: string) => ({ url: `/admin/authors/${uuid}` }),
}));
vi.mock('@/routes/admin/oeuvres', () => {
    const index = () => ({ url: '/admin/oeuvres' });
    index.url = () => '/admin/oeuvres';

    return { index, show: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}` }) };
});

const { default: Index } = await import('./Index.vue');

const STATUSES = ['submitted', 'under_review', 'registered', 'rejected', 'draft'];

const emptyPaginator = {
    data: [],
    links: [],
    from: null,
    to: null,
    total: 0,
    per_page: 25,
};

const noFilters = { search: '', status: '', author: '', from: '', to: '' };

async function render(props: Record<string, unknown>, locale = 'en') {
    const i18n = createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'en',
        messages: { en, fr, ar },
    });
    const app = createSSRApp({ render: () => h(Index as Component, props) });
    app.use(i18n);

    return renderToString(app);
}

const base = { statuses: STATUSES };

describe('admin/oeuvres/Index', () => {
    it('renders title, table header and the default empty message with no rows', async () => {
        const html = await render({
            ...base,
            oeuvres: emptyPaginator,
            filters: noFilters,
        });

        expect(html).toContain('<h1');
        expect(html).toContain(en['admin.oeuvres.title']);
        expect(html).toContain('<thead');
        expect(html).toContain(en['admin.oeuvres.colTitle']);
        expect(html).toContain('No oeuvres to show yet.');
        expect(html).toContain(en['admin.oeuvres.emptyDraftsHint']);
        expect(html).toContain('colspan="9"');
        expect(html).not.toContain(en['admin.oeuvres.resetFilters']);
    });

    it('shows the no-match message and a reset action when a filter is active', async () => {
        const html = await render({
            ...base,
            oeuvres: emptyPaginator,
            filters: { ...noFilters, status: 'draft' },
        });

        expect(html).toContain('No oeuvre matches these filters');
        expect(html).toContain(en['admin.oeuvres.resetFilters']);
        expect(html).not.toContain('No oeuvres to show yet.');
    });

    it('also treats a search term as an active filter', async () => {
        const html = await render({
            ...base,
            oeuvres: emptyPaginator,
            filters: { ...noFilters, search: 'zzz' },
        });

        expect(html).toContain('No oeuvre matches these filters');
    });

    it('renders in Arabic and French with the empty message', async () => {
        const arHtml = await render(
            { ...base, oeuvres: emptyPaginator, filters: noFilters },
            'ar',
        );
        const frHtml = await render(
            { ...base, oeuvres: emptyPaginator, filters: noFilters },
            'fr',
        );

        expect(arHtml).toContain(ar['admin.oeuvres.emptyNone']);
        expect(frHtml).toContain(fr['admin.oeuvres.emptyNone']);
    });

    it('renders a row whose author and college are null, with dashes', async () => {
        const html = await render({
            ...base,
            oeuvres: {
                ...emptyPaginator,
                data: [
                    {
                        uuid: 'u1',
                        title: null,
                        college_name: null,
                        status: 'draft',
                        author: null,
                        files_count: 0,
                        files_size_bytes: 0,
                        all_ready: false,
                        has_blocking_file: false,
                        created_at: '2026-10-01T10:00:00Z',
                        submitted_at: null,
                    },
                ],
                from: 1,
                to: 1,
                total: 1,
            },
            filters: { ...noFilters, status: 'draft' },
        });

        expect(html).toContain(en['admin.oeuvres.title']);
        expect(html).toContain('<thead');
        expect(html).toContain('—');
        expect(html).toContain(en['admin.oeuvres.view']);
        expect(html).not.toContain('NaN');
        expect(html).not.toContain('No oeuvre matches');
    });
});
