// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';
import type { MediaFileSummary, UploadFileState } from '@/types/upload';

/**
 * The author's page of a deposit under review: every file wears the ONDA's
 * decision, a rejected one shows the reason and exactly "Remplacer" and
 * "Supprimer", a slot shows a dropzone only when the server opened it, and
 * a replacement says what it replaces.
 */
vi.mock('@/routes/oeuvres/files', () => ({
    preview: () => ({ url: '/preview' }),
}));
vi.mock('@/composables/useUploadQueue', () => ({
    useUploadQueue: () => ({ selectFiles: () => [] }),
}));
vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        setup:
            (
                _props: unknown,
                { slots }: { slots: { default?: () => unknown } },
            ) =>
            () =>
                h('a', slots.default?.() as never),
    },
}));
vi.mock('@/routes/oeuvres', () => ({
    index: () => ({ url: '/author/oeuvres' }),
    show: (uuid: string) => ({ url: `/author/oeuvres/${uuid}` }),
}));

const { default: DepositCard } = await import('./DepositCard.vue');
const { default: RequirementSlotCard } =
    await import('./RequirementSlotCard.vue');
const { default: RejectedFileCallout } =
    await import('@/components/review/RejectedFileCallout.vue');
const { default: DashboardActions } =
    await import('@/components/dashboard/DashboardActions.vue');

const REASON = 'Le document est illisible, merci de le déposer à nouveau.';

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

const i18n = (locale = 'fr') =>
    createI18n({ legacy: false, locale, messages: { fr, ar, en } });

let wrapper: VueWrapper | null = null;

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
});

function card(file: MediaFileSummary, props: Record<string, unknown> = {}) {
    wrapper = mount(DepositCard, {
        props: {
            entry: { kind: 'media', file, collapsedReady: false },
            reviewMode: true,
            canReplace: true,
            ...props,
        },
        global: { plugins: [i18n()] },
        attachTo: document.body,
    });

    return wrapper;
}

const settle = async (): Promise<void> => {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 20));
    await nextTick();
};

describe('a file of a deposit under review', () => {
    it('a rejected file shows the reason, the date, and exactly "Supprimer et remplacer"', () => {
        const w = card(
            media({
                review_status: 'rejected',
                review_reason: REASON,
                reviewed_at: '2026-10-18T08:00:00+00:00',
                can_remove: true,
            }),
        );

        expect(
            w
                .find('[data-testid="review-status-badge"]')
                .attributes('data-status'),
        ).toBe('rejected');
        expect(w.find('[data-testid="rejected-reason"]').text()).toContain(
            REASON,
        );
        expect(w.find('[data-testid="rejected-callout"]').text()).toContain(
            "Rejeté par l'ONDA le",
        );
        expect(w.find('[data-testid="rejected-replace"]').text()).toBe(
            'Supprimer et remplacer',
        );
        expect(w.find('[data-testid="rejected-remove"]').exists()).toBe(false);
        // The general remove control of an editable deposit is not there.
        expect(
            w.find(`[aria-label="${fr['oeuvres.files.remove']}"]`).exists(),
        ).toBe(false);
    });

    it.each(['pending', 'accepted'] as const)(
        'a %s file shows its badge and no action',
        (status) => {
            const w = card(media({ review_status: status, can_remove: false }));

            expect(
                w
                    .find('[data-testid="review-status-badge"]')
                    .attributes('data-status'),
            ).toBe(status);
            expect(w.find('[data-testid="rejected-callout"]').exists()).toBe(
                false,
            );
            expect(
                w
                    .findAll('button')
                    .filter((b) =>
                        /Remplacer|Supprimer|Retirer/.test(b.text()),
                    ),
            ).toHaveLength(0);
        },
    );

    it('a rejected file the server will not let go shows the reason, no action', () => {
        const w = card(
            media({
                review_status: 'rejected',
                review_reason: REASON,
                can_remove: false,
            }),
        );

        expect(w.find('[data-testid="rejected-reason"]').exists()).toBe(true);
        expect(w.find('[data-testid="rejected-replace"]').exists()).toBe(false);
        expect(w.find('[data-testid="rejected-remove"]').exists()).toBe(false);
    });

    it('a replacement shows "Remplace : …" and "En attente d\'examen"', () => {
        const w = card(
            media({
                review_status: 'pending',
                replaces: { original_name: 'ancien.pdf' },
            }),
        );

        expect(w.find('[data-testid="replaces-label"]').text()).toContain(
            'Remplace :',
        );
        expect(w.find('[data-testid="replaces-label"]').text()).toContain(
            'ancien.pdf',
        );
        expect(w.find('[data-testid="review-status-badge"]').text()).toContain(
            "En attente d'examen",
        );
    });

    it('carries the #file-{uuid} anchor a notification links to', () => {
        expect(card(media()).find('#file-f1').exists()).toBe(true);
    });

    it('never names an officer', () => {
        const w = card(
            media({
                review_status: 'rejected',
                review_reason: REASON,
                can_remove: true,
            }),
        );

        expect(w.html()).not.toMatch(/Officer|Amina|reviewed_by/);
    });

    it('a failed upload whose deposit was decided says so and offers no retry', () => {
        const file = {
            id: 'u1',
            oeuvreId: 1,
            requirementId: 7,
            file: new File(['x'], 'nouveau.pdf'),
            filename: 'nouveau.pdf',
            size: 1,
            mime: 'application/pdf',
            extension: 'pdf',
            sessionUuid: 's1',
            chunkSize: 32,
            totalChunks: 1,
            chunks: [],
            status: 'failed',
            errorCode: 'oeuvreDecided',
            errorChunkIndex: null,
            remainingQuotaBytes: null,
            neededQuotaBytes: null,
            errorMessage: null,
            errorStatus: null,
            notice: null,
            bytesUploaded: 0,
            speedBps: 0,
            etaSeconds: null,
            expiresAt: null,
            mediaFileUuid: null,
            needsFileReselect: false,
        } satisfies UploadFileState;

        wrapper = mount(DepositCard, {
            props: { entry: { kind: 'upload', file } },
            global: { plugins: [i18n()] },
        });

        expect(wrapper.text()).toContain(
            "Le dépôt a été traité, le fichier n'a pas été ajouté.",
        );
        expect(
            wrapper
                .find(`[aria-label="${fr['upload.actions.retry']}"]`)
                .exists(),
        ).toBe(false);
    });
});

describe('the rejected-file callout', () => {
    function callout(props: Record<string, unknown> = {}) {
        wrapper = mount(RejectedFileCallout, {
            props: {
                fileName: 'contrat.pdf',
                reason: REASON,
                reviewedAt: null,
                canRemove: true,
                canReplace: true,
                ...props,
            },
            global: { plugins: [i18n()] },
            attachTo: document.body,
        });

        return wrapper;
    }

    it('"Remplacer" asks first, with the exact wording, then emits replace', async () => {
        const w = callout();

        await w.find('[data-testid="rejected-replace"]').trigger('click');
        await settle();

        expect(document.body.textContent).toContain(
            'Le fichier actuel sera supprimé définitivement. Vous pourrez ensuite téléverser le nouveau.',
        );
        // The dialog recalls the file and the reason.
        expect(document.body.textContent).toContain('contrat.pdf');
        expect(
            document.querySelector('[data-testid="removal-reason"]')
                ?.textContent,
        ).toContain(REASON);
        (
            document.querySelector(
                '[data-testid="removal-confirm"]',
            ) as HTMLButtonElement
        ).click();
        await settle();

        expect(w.emitted('replace')).toHaveLength(1);
        expect(w.emitted('remove')).toBeUndefined();
    });

    it('without a slot, "Supprimer" asks first, then emits remove', async () => {
        const w = callout({ canReplace: false });

        await w.find('[data-testid="rejected-remove"]').trigger('click');
        await settle();
        expect(document.body.textContent).toContain(
            "Le fichier sera supprimé. L'emplacement restera ouvert pour un nouveau fichier.",
        );
        (
            document.querySelector(
                '[data-testid="removal-confirm"]',
            ) as HTMLButtonElement
        ).click();
        await settle();

        expect(w.emitted('remove')).toHaveLength(1);
        expect(w.emitted('replace')).toBeUndefined();
    });

    it('a file with no slot to replace into offers "Supprimer" only', () => {
        const w = callout({ canReplace: false });

        expect(w.find('[data-testid="rejected-replace"]').exists()).toBe(false);
        expect(w.find('[data-testid="rejected-remove"]').exists()).toBe(true);
    });

    it('renders in Arabic', () => {
        wrapper = mount(RejectedFileCallout, {
            props: {
                fileName: 'contrat.pdf',
                reason: REASON,
                reviewedAt: null,
                canRemove: true,
                canReplace: true,
            },
            global: { plugins: [i18n('ar')] },
        });

        expect(wrapper.text()).toContain('حذف واستبدال');
        expect(wrapper.text()).toContain('سبب الرفض');
    });
});

describe('a requirement slot under review', () => {
    const requirement = {
        id: 7,
        document_key: 'justificatif',
        title: 'Justificatif',
        extensions: ['pdf'],
        is_required: true,
        max_size_kb: null,
        allows_multiple: true,
        conditions: null,
    };

    function slot(replacementOpen: number) {
        wrapper = mount(RequirementSlotCard, {
            props: {
                requirement,
                position: 1,
                oeuvreId: 1,
                entries: [],
                quota: { used_bytes: 0, limit_bytes: 100 },
                editable: false,
                reviewMode: true,
                replacementOpen,
            } as never,
            global: { plugins: [i18n()] },
        });

        return wrapper;
    }

    it('without an open replacement: no dropzone at all', () => {
        const w = slot(0);

        expect(w.find('[data-testid="replacement-expected"]').exists()).toBe(
            false,
        );
        expect(w.find('input[type="file"]').exists()).toBe(false);
    });

    it('with one open: a "Remplacement attendu" dropzone the page can focus', () => {
        const w = slot(1);

        expect(w.find('[data-testid="replacement-expected"]').text()).toBe(
            'Remplacement attendu',
        );
        expect(w.find('#replacement-slot-7').exists()).toBe(true);
        // One replacement: one file at a time.
        expect(
            w.find('input[type="file"]').attributes('multiple'),
        ).toBeUndefined();
    });
});

describe('the dashboard item', () => {
    const base = {
        uuid: 'o1',
        label_source: {
            uuid: 'o1',
            title: 'Symphonie',
            college_name: null,
            created_at: '2026-10-01T08:00:00+00:00',
        },
        status: 'under_review',
        blockers: [],
        rejection: null,
    };

    it('files to replace: the count and "Remplacer les fichiers"', () => {
        wrapper = mount(DashboardActions, {
            props: {
                actions: {
                    items: [
                        {
                            ...base,
                            kind: 'replace',
                            replacement: { to_replace: 2, pending: 0 },
                        },
                    ],
                    total: 1,
                },
            },
            global: { plugins: [i18n()] },
        });

        expect(wrapper.find('[data-testid="action-to-replace"]').text()).toBe(
            '2 fichiers à remplacer',
        );
        expect(wrapper.text()).toContain('Remplacer les fichiers');
    });

    it('every replacement handed in: "En attente de l\'ONDA"', () => {
        wrapper = mount(DashboardActions, {
            props: {
                actions: {
                    items: [
                        {
                            ...base,
                            kind: 'awaiting',
                            replacement: { to_replace: 0, pending: 1 },
                        },
                    ],
                    total: 1,
                },
            },
            global: { plugins: [i18n()] },
        });

        expect(
            wrapper.find('[data-testid="action-awaiting-note"]').text(),
        ).toBe("En attente de l'ONDA");
    });
});
