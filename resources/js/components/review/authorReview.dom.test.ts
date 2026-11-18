// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';
import type { MediaFileSummary } from '@/types/upload';

/**
 * The author's view of a deposit under review: the state language (icon +
 * words, never colour alone), the summary, "À corriger", the mobile bar,
 * the removal dialog, the replacement dropzone and the per-document
 * history — in fr and ar, with and without reduced motion.
 */
vi.mock('@/routes/oeuvres/files', () => ({
    preview: () => ({ url: '/preview' }),
}));

const { default: DepositCard } =
    await import('@/components/upload/DepositCard.vue');
const { default: Dropzone } = await import('@/components/upload/Dropzone.vue');
const { default: ReviewSummary } = await import('./ReviewSummary.vue');
const { default: ToFixPanel } = await import('./ToFixPanel.vue');
const { default: MobileFixBar } = await import('./MobileFixBar.vue');
const { default: SlotHistory } = await import('./SlotHistory.vue');
const { default: FileRemovalDialog } = await import('./FileRemovalDialog.vue');
const { useUploadQueue } = await import('@/composables/useUploadQueue');

const REASON = 'Le document est illisible, merci de le déposer à nouveau.';

const i18n = (locale = 'fr') =>
    createI18n({ legacy: false, locale, messages: { fr, ar, en } });

let wrapper: VueWrapper | null = null;

const settle = async (): Promise<void> => {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 20));
    await nextTick();
};

function reducedMotion(on: boolean): void {
    vi.stubGlobal(
        'matchMedia',
        vi.fn((query: string) => ({
            matches: on && query.includes('reduce'),
            media: query,
            addEventListener: () => undefined,
            removeEventListener: () => undefined,
            addListener: () => undefined,
            removeListener: () => undefined,
        })),
    );
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

function media(overrides: Partial<MediaFileSummary> = {}): MediaFileSummary {
    return {
        uuid: 'f1',
        can_remove: false,
        college_oeuvre_file_id: 7,
        original_name: 'contrat.pdf',
        extension: 'pdf',
        mime: 'application/pdf',
        size_bytes: 2048,
        status: 'ready',
        duration_sec: null,
        width: null,
        height: null,
        sha256_plain: 'a'.repeat(64),
        variant_count: 0,
        created_at: '2026-10-14T20:00:00+00:00',
        review_status: 'pending',
        review_reason: null,
        reviewed_at: null,
        replaces: null,
        ...overrides,
    };
}

function card(file: MediaFileSummary, locale = 'fr') {
    wrapper = mount(DepositCard, {
        props: {
            entry: { kind: 'media', file, collapsedReady: false },
            reviewMode: true,
            canReplace: true,
        },
        global: { plugins: [i18n(locale)] },
        attachTo: document.body,
    });

    return wrapper;
}

const actionButtons = (w: VueWrapper) =>
    w
        .findAll('button')
        .filter((b) => /Remplacer|Supprimer|Retirer|réessayer/.test(b.text()));

describe('the state of each file', () => {
    it('accepted: the lock, its tooltip text, no action, a success accent', () => {
        const w = card(media({ review_status: 'accepted' }));

        const lock = w.find('[data-testid="accepted-lock"]');
        expect(lock.attributes('aria-label')).toBe(
            "Accepté par l'ONDA : ce fichier ne peut plus être modifié",
        );
        expect(lock.text()).toContain('Accepté');
        expect(actionButtons(w)).toHaveLength(0);
        expect(w.find('[data-testid="review-accent"]').classes()).toContain(
            'bg-emerald-500',
        );
    });

    it('rejected: red row with an icon and the label, the callout, one action', () => {
        const w = card(
            media({
                review_status: 'rejected',
                review_reason: REASON,
                can_remove: true,
            }),
        );

        expect(w.find('[data-testid="review-accent"]').classes()).toContain(
            'bg-destructive',
        );
        const badge = w.find('[data-testid="review-status-badge"]');
        expect(badge.text()).toContain('Rejeté');
        expect(badge.find('svg').exists()).toBe(true);
        expect(w.find('[data-testid="rejected-signature"]').text()).toContain(
            'ONDA',
        );
        expect(w.find('[data-testid="rejected-instruction"]').text()).toBe(
            'Supprimez ce fichier, puis téléversez un nouveau fichier conforme.',
        );
        expect(w.find('[data-testid="rejected-heading"]').text()).toBe(
            'Motif du rejet',
        );
        expect(actionButtons(w).map((b) => b.text())).toEqual([
            'Supprimer et remplacer',
        ]);
    });

    it('pending: a clock and "En attente d\'examen", no action', () => {
        const w = card(media({ review_status: 'pending' }));

        const badge = w.find('[data-testid="review-status-badge"]');
        expect(badge.text()).toContain("En attente d'examen");
        expect(badge.find('svg').exists()).toBe(true);
        expect(actionButtons(w)).toHaveLength(0);
    });

    it('a pending replacement the server lets go offers "Retirer ce remplacement"', () => {
        const w = card(
            media({
                review_status: 'pending',
                can_remove: true,
                replaces: { original_name: 'ancien.pdf' },
            }),
        );

        expect(w.find('[data-testid="replaces-label"]').text()).toContain(
            'ancien.pdf',
        );
        expect(w.find('[data-testid="replacement-action"]').text()).toContain(
            'Retirer ce remplacement',
        );
    });

    it('a failed replacement offers "Supprimer et réessayer"', () => {
        const w = card(
            media({
                status: 'failed',
                review_status: 'pending',
                can_remove: true,
                replaces: { original_name: 'ancien.pdf' },
            }),
        );

        expect(w.find('[data-testid="replacement-action"]').text()).toContain(
            'Supprimer et réessayer',
        );
    });

    it('a long reason folds behind "Voir plus"', async () => {
        const w = card(
            media({
                review_status: 'rejected',
                review_reason: REASON.repeat(6),
                can_remove: true,
            }),
        );

        expect(w.find('[data-testid="rejected-reason"]').classes()).toContain(
            'line-clamp-4',
        );
        await w.find('[data-testid="rejected-reason-toggle"]').trigger('click');
        expect(
            w.find('[data-testid="rejected-reason"]').classes(),
        ).not.toContain('line-clamp-4');
    });

    it('renders in Arabic', () => {
        const w = card(media({ review_status: 'accepted' }), 'ar');

        expect(w.find('[data-testid="accepted-lock"]').text()).toContain(
            'مقبول',
        );
    });
});

describe('the summary', () => {
    it('renders the three counters and the segments from props; zero shows 0', () => {
        reducedMotion(true);
        wrapper = mount(ReviewSummary, {
            props: { accepted: 2, waiting: 1, toFix: 0 },
            global: { plugins: [i18n()] },
        });

        expect(
            wrapper.find('[data-testid="summary-accepted"]').text(),
        ).toContain('2');
        expect(
            wrapper.find('[data-testid="summary-waiting"]').text(),
        ).toContain('1');
        expect(wrapper.find('[data-testid="summary-toFix"]').text()).toContain(
            '0',
        );
        expect(
            (wrapper.find('[data-segment="accepted"]').element as HTMLElement)
                .style.width,
        ).toBe('66.66666666666666%');
        expect(
            (wrapper.find('[data-segment="toFix"]').element as HTMLElement)
                .style.width,
        ).toBe('0%');
    });
});

describe('"À corriger"', () => {
    const items = [
        {
            target: 'file-a',
            name: 'a.pdf',
            slot: 'Contrat',
            kind: 'file' as const,
        },
        {
            target: 'replacement-slot-3',
            name: 'Justificatif',
            slot: 'Justificatif',
            kind: 'slot' as const,
        },
    ];

    it('lists the files, announces once, jumps to each, and cycles', async () => {
        wrapper = mount(ToFixPanel, {
            props: { items, allSent: false, lastUploadAt: null },
            global: { plugins: [i18n()] },
        });

        const panel = wrapper.find('[data-testid="to-fix-panel"]');
        expect(panel.attributes('role')).toBe('alert');
        expect(wrapper.findAll('[data-testid="to-fix-item"]')).toHaveLength(2);

        await wrapper.findAll('[data-testid="to-fix-go"]')[0]!.trigger('click');
        await wrapper.find('[data-testid="to-fix-next"]').trigger('click');
        await wrapper.find('[data-testid="to-fix-next"]').trigger('click');
        await wrapper.find('[data-testid="to-fix-next"]').trigger('click');

        expect(wrapper.emitted('go')!.map(([target]) => target)).toEqual([
            'file-a',
            'file-a',
            'replacement-slot-3',
            'file-a',
        ]);

        await new Promise((resolve) => setTimeout(resolve, 1600));
        await wrapper.setProps({ items: [...items] });
        // A refresh never re-announces.
        expect(
            wrapper.find('[data-testid="to-fix-panel"]').attributes('role'),
        ).toBeUndefined();
    });

    it('"all replacements sent" only when nothing is left to fix', async () => {
        wrapper = mount(ToFixPanel, {
            props: {
                items,
                allSent: true,
                lastUploadAt: '2026-10-18T10:00:00+00:00',
            },
            global: { plugins: [i18n()] },
        });
        expect(wrapper.find('[data-testid="all-sent"]').exists()).toBe(false);

        await wrapper.setProps({ items: [] });
        expect(wrapper.find('[data-testid="all-sent"]').text()).toContain(
            "Tous les remplacements ont été envoyés. L'ONDA va les examiner.",
        );

        // Still uploading (the page passes allSent=false): no fake "done".
        await wrapper.setProps({ allSent: false });
        expect(wrapper.find('[data-testid="all-sent"]').exists()).toBe(false);
    });
});

describe('the mobile bar', () => {
    it('appears only when something is to fix', async () => {
        wrapper = mount(MobileFixBar, {
            props: { count: 0 },
            global: { plugins: [i18n()] },
            attachTo: document.body,
        });
        const bar = () =>
            document.querySelector<HTMLElement>(
                '[data-testid="mobile-fix-bar"]',
            );
        expect(bar()).toBeNull();

        await wrapper.setProps({ count: 2 });
        // Teleported to <body>, so `fixed` really pins it to the viewport.
        expect(bar()?.parentElement).toBe(document.body);
        expect(bar()?.textContent).toContain(
            '2 fichiers rejetés : à remplacer',
        );
        expect(bar()?.classList.contains('sm:hidden')).toBe(true);
        bar()!.querySelector('button')!.click();
        await nextTick();
        expect(wrapper.emitted('go')).toHaveLength(1);
    });
});

describe('the removal dialog', () => {
    it('blocks Escape while busy', async () => {
        wrapper = mount(FileRemovalDialog, {
            props: {
                open: true,
                mode: 'remove',
                fileName: 'a.pdf',
                busy: true,
            },
            global: { plugins: [i18n()] },
            attachTo: document.body,
        });
        await settle();

        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await settle();

        expect(wrapper.emitted('update:open')).toBeUndefined();
        expect(
            (
                document.querySelector(
                    '[data-testid="removal-confirm"]',
                ) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });
});

describe('the replacement dropzone', () => {
    const requirement = {
        id: 7,
        document_key: 'justificatif',
        title: 'Justificatif',
        extensions: ['pdf'],
        is_required: true,
        max_size_kb: 1024,
        allows_multiple: true,
        conditions: null,
    };

    it("shows the slot's formats, its size and the reason, and pulses once", async () => {
        reducedMotion(false);
        wrapper = mount(Dropzone, {
            props: {
                oeuvreId: 1,
                requirement,
                replacement: true,
                reminder: REASON,
            },
            global: { plugins: [i18n()] },
        });

        const helper = wrapper
            .find('[data-testid="replacement-requirement"]')
            .text();
        expect(helper).toContain('PDF');
        expect(helper).toContain('1');
        expect(helper).toMatch(/Mo|MB/);
        expect(
            wrapper.find('[data-testid="replacement-reminder"]').text(),
        ).toContain(REASON);
        await nextTick();
        expect(wrapper.html()).toContain('motion-pulse-once');
    });

    it('under reduced motion it does not pulse', () => {
        reducedMotion(true);
        wrapper = mount(Dropzone, {
            props: { oeuvreId: 1, requirement, replacement: true },
            global: { plugins: [i18n()] },
        });

        expect(wrapper.html()).not.toContain('motion-pulse-once');
    });

    it('a wrong type or size never enters the queue', () => {
        const queue = useUploadQueue();
        const before = queue.files.value.length;
        const slot = { id: 7, extensions: ['pdf'], maxSizeBytes: 1024 };

        const rejections = queue.selectFiles(
            [
                new File(['x'], 'photo.png', { type: 'image/png' }),
                new File(['x'.repeat(2048)], 'gros.pdf', {
                    type: 'application/pdf',
                }),
            ],
            1,
            slot,
        );

        expect(rejections.map((r) => r.reason)).toEqual(['extension', 'size']);
        expect(queue.files.value.length).toBe(before);
    });
});

describe('the history of a document', () => {
    it('renders in order, signed ONDA, never a name', async () => {
        wrapper = mount(SlotHistory, {
            props: {
                events: [
                    {
                        at: '2026-10-18T08:00:00+00:00',
                        kind: 'uploaded',
                        file: 'a.pdf',
                        reason: null,
                    },
                    {
                        at: '2026-10-18T09:00:00+00:00',
                        kind: 'rejected',
                        file: 'a.pdf',
                        reason: REASON,
                    },
                    {
                        at: '2026-10-18T10:00:00+00:00',
                        kind: 'removed',
                        file: 'a.pdf',
                        reason: null,
                    },
                    {
                        at: '2026-10-18T11:00:00+00:00',
                        kind: 'replacement',
                        file: 'b.pdf',
                        reason: null,
                    },
                ],
            },
            global: { plugins: [i18n()] },
            attachTo: document.body,
        });

        await wrapper.find('button').trigger('click');
        await settle();

        const events = wrapper.findAll('[data-testid="history-event"]');
        expect(events.map((e) => e.attributes('data-kind'))).toEqual([
            'uploaded',
            'rejected',
            'removed',
            'replacement',
        ]);
        expect(events[1]!.text()).toContain(REASON);
        expect(events[1]!.text()).toContain('ONDA');
        expect(events[2]!.text()).toContain('Supprimé par vous');
        expect(wrapper.html()).not.toMatch(/Officer|Amina|reviewer/i);
    });
});
