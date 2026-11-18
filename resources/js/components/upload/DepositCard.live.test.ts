import { renderToString } from '@vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, h } from 'vue';
import { createI18n } from 'vue-i18n';
import type { LiveFileStatus } from '@/composables/useFileStatusPoll';
import fr from '@/locales/fr.json';
import type { MediaFileStatus, MediaFileSummary } from '@/types/upload';
import { mergeDepositEntries } from './depositJourney';

vi.mock('@/routes/oeuvres/files', () => ({
    preview: () => ({ url: '/preview' }),
}));

const { default: DepositCard } = await import('./DepositCard.vue');

const shown: MediaFileSummary = {
    uuid: '01a13c5b-ba50-721a-919a-0942f6cc4e0b',
    can_remove: false,
    college_oeuvre_file_id: 19,
    original_name: 'recueil.pdf',
    extension: 'pdf',
    mime: 'application/pdf',
    size_bytes: 9_016_380,
    status: 'scanning',
    duration_sec: null,
    width: null,
    height: null,
    sha256_plain: null,
    variant_count: 0,
    created_at: '2026-10-14T20:00:00+00:00',
} as MediaFileSummary;

async function render(status: MediaFileStatus | null): Promise<string> {
    const live: Record<string, LiveFileStatus> = status
        ? {
              [shown.uuid]: {
                  id: shown.uuid,
                  status,
                  stage: null,
                  error: null,
              },
          }
        : {};
    const [entry] = mergeDepositEntries([], 1, [shown], new Set(), live);
    const app = createSSRApp({ render: () => h(DepositCard, { entry }) });
    app.use(createI18n({ legacy: false, locale: 'fr', messages: { fr } }));

    return renderToString(app);
}

describe('DepositCard with a live status', () => {
    it('shows step 4 while the server says scanning', async () => {
        const html = await render(null);

        expect(html).toContain('(4/6)');
        expect(html).not.toContain('role="alert"');
    });

    it('a failed result from the poll shows the error state, not step 4', async () => {
        const html = await render('failed');

        expect(html).toContain('role="alert"');
        expect(html).toContain(
            fr['media.failedWithRef']
                .replace('{ref}', '01a13c5b')
                .replace(/'/g, '&#39;'),
        );
        expect(html).not.toContain('(4/6)');
    });

    it('a quarantined (infected) result shows the quarantine state', async () => {
        const html = await render('quarantined');

        expect(html).toContain('role="alert"');
        expect(html).toContain('border-amber-500/40');
        expect(html).not.toContain('(4/6)');
    });
});
