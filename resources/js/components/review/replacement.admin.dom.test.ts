// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';

/**
 * The officer's side of a replacement: the badge and what it replaces (the
 * previous reason one click away), the read-only list of files the author
 * removed, the non-blocking checklist advisory, "Suivant à examiner", and
 * the `replacement_uploaded` row in the bell.
 */
const inertia = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: inertia.visit, on: () => () => undefined },
}));
vi.mock('@/routes/notifications', () => ({
    index: () => ({ url: '/notifications' }),
    read: (id: string) => ({ url: `/notifications/${id}/read` }),
    readAll: () => ({ url: '/notifications/read-all' }),
}));

const { default: ReplacementInfo } = await import('./ReplacementInfo.vue');
const { default: RemovedFilesSection } =
    await import('./RemovedFilesSection.vue');
const { default: DecisionChecklist } = await import('./DecisionChecklist.vue');
const { default: NotificationPopover } =
    await import('@/components/dashboard/NotificationPopover.vue');
const { nextToReview } = await import('./fileReview');

const REASON = 'Le document est illisible, merci de le déposer à nouveau.';

const i18n = (locale = 'fr') =>
    createI18n({ legacy: false, locale, messages: { fr, ar, en } });

let wrapper: VueWrapper | null = null;

const settle = async (): Promise<void> => {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 30));
    await nextTick();
};

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('a replacement row', () => {
    it('shows the badge, what it replaces, and the previous reason one click away', async () => {
        wrapper = mount(ReplacementInfo, {
            props: {
                replaces: {
                    original_name: 'ancien.pdf',
                    review_reason: REASON,
                },
            },
            global: { plugins: [i18n()] },
            attachTo: document.body,
        });

        expect(wrapper.text()).toContain('Remplacement');
        expect(wrapper.text()).toContain('Remplace :');
        expect(wrapper.text()).toContain('ancien.pdf');
        expect(document.body.textContent).not.toContain(REASON);

        await wrapper
            .find('[data-testid="previous-reason-trigger"]')
            .trigger('click');
        await settle();

        expect(
            document.querySelector('[data-testid="previous-reason"]')
                ?.textContent,
        ).toContain(REASON);
    });
});

describe('files removed by the author', () => {
    const files = [
        {
            uuid: 'r1',
            original_name: 'parti.pdf',
            review_reason: REASON,
            removed_at: '2026-10-18T09:00:00+00:00',
            replaced_by: {
                uuid: 'n1',
                original_name: 'nouveau.pdf',
                removed: false,
            },
        },
        {
            uuid: 'r2',
            original_name: 'autre.pdf',
            review_reason: 'Format non conforme au type demandé.',
            removed_at: '2026-10-18T10:00:00+00:00',
            replaced_by: null,
        },
    ];

    it('is collapsed, then lists reason, date and what replaced each one', async () => {
        wrapper = mount(RemovedFilesSection, {
            props: { files, hrefFor: (uuid: string) => `#file-row-${uuid}` },
            global: { plugins: [i18n()] },
            attachTo: document.body,
        });

        expect(wrapper.text()).toContain("Fichiers retirés par l'auteur (2)");
        expect(wrapper.findAll('[data-testid="removed-file"]')).toHaveLength(0);

        await wrapper.find('button').trigger('click');
        await settle();

        const rows = wrapper.findAll('[data-testid="removed-file"]');

        expect(rows).toHaveLength(2);
        expect(rows[0]!.text()).toContain(REASON);
        expect(rows[0]!.text()).toContain('Retiré le');
        expect(
            rows[0]!
                .find('[data-testid="replaced-by-link"]')
                .attributes('href'),
        ).toBe('#file-row-n1');
        expect(rows[1]!.text()).toContain('Pas encore remplacé');
        // Evidence: nothing to act on.
        expect(wrapper.findAll('button')).toHaveLength(1);
    });

    it('renders nothing when the author removed nothing', () => {
        wrapper = mount(RemovedFilesSection, {
            props: { files: [], hrefFor: () => '' },
            global: { plugins: [i18n()] },
        });

        expect(wrapper.find('[data-testid="removed-files"]').exists()).toBe(
            false,
        );
    });
});

describe('the decision checklist', () => {
    it('names an unreplaced slot as an advisory, and never as a blocker', () => {
        wrapper = mount(DecisionChecklist, {
            props: {
                checklist: {
                    noFiles: false,
                    requiredMissing: [],
                    filesNotReady: [],
                    conditionalMissing: [],
                    filesNotAccepted: [],
                    openReplacementSlots: ['Justificatif / Contrat d’édition'],
                },
            },
            global: { plugins: [i18n()] },
        });

        const advisory = wrapper.find(
            '[data-testid="check-open-replacements"]',
        );

        expect(advisory.text()).toContain('Remplacement non fourni');
        expect(advisory.text()).toContain('Justificatif / Contrat d’édition');
        expect(
            wrapper
                .find('[data-testid="check-accepted"]')
                .attributes('data-ok'),
        ).toBe('true');
        expect(
            wrapper
                .find('[data-testid="check-required"]')
                .attributes('data-ok'),
        ).toBe('true');
    });
});

describe('Suivant à examiner', () => {
    it('includes a pending replacement', () => {
        const files = [
            { uuid: 'a', review_status: 'accepted' },
            { uuid: 'b', review_status: 'accepted' },
            // The replacement: a live file like any other, pending.
            {
                uuid: 'n1',
                review_status: 'pending',
                replaces: { original_name: 'parti.pdf' },
            },
        ];

        expect(nextToReview(files, 'a')?.uuid).toBe('n1');
    });
});

describe('the admin bell', () => {
    it('renders replacement_uploaded and opens the replacement review page', async () => {
        const row = {
            id: 'n1',
            data: {
                type: 'replacement_uploaded',
                label_source: {
                    uuid: '0199-abcd',
                    title: 'Symphonie des Aurès',
                    college_name: 'Musique',
                    created_at: '2026-10-01T08:00:00+00:00',
                },
                i18n_key: 'notifications.file.replacement',
                params: {
                    file: 'nouveau.pdf',
                    old: 'ancien.pdf',
                    author: 'Author Person',
                },
                reason: REASON,
                url: 'http://onda-storage.test/admin/oeuvres/0199-abcd/files/f2/review',
            },
            read_at: null,
            created_at: new Date().toISOString(),
        };

        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: true,
                status: 200,
                json: async () => ({ unread_count: 1, notifications: [row] }),
            })),
        );

        wrapper = mount(NotificationPopover, {
            global: { plugins: [i18n()] },
            attachTo: document.body,
        });
        await settle();
        await wrapper
            .find('[data-testid="notification-bell"]')
            .trigger('click');
        await settle();

        const item = document.querySelector(
            '[data-type="replacement_uploaded"]',
        ) as HTMLElement;

        expect(item).not.toBeNull();
        expect(item.textContent).toContain(
            'Fichier de remplacement « nouveau.pdf » prêt à examiner (remplace « ancien.pdf »).',
        );

        item.click();
        await settle();

        expect(inertia.visit).toHaveBeenCalledWith(
            '/admin/oeuvres/0199-abcd/files/f2/review',
        );
    });
});
