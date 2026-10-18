// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { h, nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';
import type { MediaFileSummary } from '@/types/upload';

/**
 * The author's page once the ONDA has decided files — under review, or on a
 * deposit returned to its author (rejected): each rejected file in red with
 * its OWN reason and one action, "Supprimer et remplacer"; accepted files
 * locked with no action at all; the top alert and its "Aller au fichier";
 * the removal then the focused dropzone; the blocked "Soumettre à nouveau";
 * the localized breadcrumb. In fr and ar, with and without reduced motion.
 */
vi.mock('@/routes/oeuvres/files', () => ({
    preview: () => ({ url: '/preview' }),
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
                h('a', {}, slots.default?.() as never),
    },
}));

const { default: DepositCard } =
    await import('@/components/upload/DepositCard.vue');
const { default: RequirementSlotCard } =
    await import('@/components/upload/RequirementSlotCard.vue');
const { default: Dropzone } = await import('@/components/upload/Dropzone.vue');
const { default: ToFixPanel } = await import('./ToFixPanel.vue');
const { default: RejectedFileCallout } =
    await import('./RejectedFileCallout.vue');
const { default: SubmitArea } =
    await import('@/components/oeuvre/SubmitArea.vue');
const { default: Breadcrumbs } = await import('@/components/Breadcrumbs.vue');
const { useUploadQueue } = await import('@/composables/useUploadQueue');
const {
    createRemovalFlow,
    focusReplacementDropzone,
    replacementDropzoneId,
    revealTarget,
} = await import('@/components/upload/replacement');

const REASON_A = 'Le contrat n’est pas signé par les deux parties.';
const REASON_B = 'La partition est illisible à partir de la page 3.';

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
    document.documentElement.removeAttribute('dir');
    document.documentElement.classList.remove('dark');
    vi.unstubAllGlobals();
    vi.useRealTimers();
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

/** A card on a deposit returned to its author: editable, decisions shown. */
function card(file: MediaFileSummary, locale = 'fr', editable = true) {
    wrapper = mount(DepositCard, {
        props: {
            entry: { kind: 'media', file, collapsedReady: false },
            editable,
            reviewMode: true,
            canReplace: true,
        },
        global: { plugins: [i18n(locale)] },
        attachTo: document.body,
    });

    return wrapper;
}

const trash = (w: VueWrapper) =>
    w.find(`[aria-label="${fr['oeuvres.files.remove']}"]`);

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

describe('each state has an icon and a label, never colour alone', () => {
    it.each([true, false])(
        'accepted (editable deposit: %s): lock, tooltip text, no trash, no action',
        (editable) => {
            const w = card(
                media({ review_status: 'accepted', can_remove: false }),
                'fr',
                editable,
            );

            const lock = w.find('[data-testid="accepted-lock"]');
            expect(lock.attributes('aria-label')).toBe(
                "Accepté par l'ONDA : ce fichier ne peut plus être modifié",
            );
            expect(lock.find('svg').exists()).toBe(true);
            expect(lock.text()).toContain('Accepté');
            expect(trash(w).exists()).toBe(false);
            // No change or removal action (copying the fingerprint is not one).
            expect(
                w
                    .findAll('button')
                    .filter((b) =>
                        /Supprimer|Remplacer|Retirer|réessayer/.test(b.text()),
                    ),
            ).toHaveLength(0);
            expect(w.find('[data-testid="rejected-callout"]').exists()).toBe(
                false,
            );
        },
    );

    it('rejected: accent bar, tinted row, circle-x badge "Rejeté", its callout, one action, no trash', () => {
        const w = card(
            media({
                review_status: 'rejected',
                review_reason: REASON_A,
                reviewed_at: '2026-10-17T09:00:00+00:00',
                can_remove: true,
            }),
        );

        const row = w.find('[data-review-status="rejected"]');
        expect(row.classes()).toContain('bg-destructive/5');
        expect(w.find('[data-testid="review-accent"]').classes()).toContain(
            'bg-destructive',
        );
        const badge = w.find('[data-testid="review-status-badge"]');
        expect(badge.text()).toContain('Rejeté');
        expect(badge.find('svg').exists()).toBe(true);
        const callout = w.find('[data-testid="rejected-callout"]');
        expect(callout.find('[data-testid="rejected-heading"]').text()).toBe(
            'Motif du rejet',
        );
        expect(callout.text()).toContain(REASON_A);
        expect(callout.text()).toContain("Rejeté par l'ONDA le");
        expect(callout.text()).toContain(
            'Supprimez ce fichier, puis téléversez un nouveau fichier conforme.',
        );
        expect(trash(w).exists()).toBe(false);
        expect(
            w
                .findAll('button')
                .filter((b) => b.text().includes('Supprimer'))
                .map((b) => b.text()),
        ).toEqual(['Supprimer et remplacer']);
    });

    it('pending on a returned deposit: clock and "En attente d\'examen", the trash stays', () => {
        const w = card(media({ review_status: 'pending', can_remove: true }));

        const badge = w.find('[data-testid="review-status-badge"]');
        expect(badge.text()).toContain("En attente d'examen");
        expect(badge.find('svg').exists()).toBe(true);
        expect(trash(w).exists()).toBe(true);
    });

    it('a replacement: neutral row, "En attente d\'examen", "Remplace : …"', () => {
        const w = card(
            media({
                review_status: 'pending',
                replaces: { original_name: 'ancien.pdf' },
            }),
        );

        expect(
            w.find('[data-review-status="pending"]').classes(),
        ).not.toContain('bg-destructive/5');
        expect(w.find('[data-testid="replaces-label"]').text()).toContain(
            'Remplace : ancien.pdf',
        );
    });
});

describe('one callout per rejected file', () => {
    it('two rejected files show two callouts, each with its own reason', () => {
        const rejected = (uuid: string, name: string, reason: string) => ({
            kind: 'media' as const,
            file: media({
                uuid,
                original_name: name,
                review_status: 'rejected',
                review_reason: reason,
                can_remove: true,
            }),
            collapsedReady: false,
        });

        wrapper = mount(RequirementSlotCard, {
            props: {
                requirement,
                position: 1,
                oeuvreId: 1,
                entries: [
                    rejected('a', 'contrat.pdf', REASON_A),
                    rejected('b', 'partition.pdf', REASON_B),
                ],
                quota: { used_bytes: 0, limit_bytes: 100 },
                editable: true,
                reviewMode: true,
                reviewState: 'to_fix',
            } as never,
            global: { plugins: [i18n()] },
        });

        const callouts = wrapper.findAll('[data-testid="rejected-callout"]');
        expect(callouts).toHaveLength(2);
        expect(callouts[0]!.text()).toContain(REASON_A);
        expect(callouts[0]!.text()).not.toContain(REASON_B);
        expect(callouts[1]!.text()).toContain(REASON_B);
        expect(callouts[1]!.text()).not.toContain(REASON_A);
        // Each callout sits inside its own file row.
        expect(
            wrapper
                .find('#file-a')
                .find('[data-testid="rejected-callout"]')
                .text(),
        ).toContain(REASON_A);
        expect(
            wrapper.find('[data-testid="slot-review-state"]').text(),
        ).toContain('À corriger');
    });
});

describe('the top alert and "Aller au fichier"', () => {
    it('lists each rejected file with its slot; the link focuses the row and pulses once', async () => {
        reducedMotion(false);
        const row = document.createElement('div');
        row.id = 'file-a';
        row.tabIndex = -1;
        document.body.appendChild(row);

        wrapper = mount(ToFixPanel, {
            props: {
                items: [
                    {
                        target: 'file-a',
                        name: 'contrat.pdf',
                        slot: 'Contrat',
                        kind: 'file',
                    },
                    {
                        target: 'file-b',
                        name: 'partition.pdf',
                        slot: 'Partition',
                        kind: 'file',
                    },
                ],
                allSent: false,
                lastUploadAt: null,
            },
            global: { plugins: [i18n()] },
            attachTo: document.body,
        });

        expect(wrapper.find('h2').text()).toContain(
            '2 fichiers rejetés : à remplacer',
        );
        const items = wrapper.findAll('[data-testid="to-fix-item"]');
        expect(items.map((i) => i.text())).toEqual([
            expect.stringContaining('contrat.pdf'),
            expect.stringContaining('partition.pdf'),
        ]);
        expect(items[0]!.text()).toContain('Contrat');

        await items[0]!.find('[data-testid="to-fix-go"]').trigger('click');
        const target = wrapper.emitted('go')![0]![0] as string;

        expect(revealTarget(target, false)).toBe(true);
        expect(document.activeElement).toBe(row);
        expect(row.classList.contains('motion-pulse-once')).toBe(true);
    });

    it('under reduced motion: focus, no smooth scroll, no pulse', () => {
        const row = document.createElement('div');
        row.id = 'file-a';
        row.tabIndex = -1;
        const scroll = vi.fn();
        row.scrollIntoView = scroll;
        document.body.appendChild(row);

        revealTarget('file-a', true);

        expect(document.activeElement).toBe(row);
        expect(scroll).toHaveBeenCalledWith({
            behavior: 'auto',
            block: 'center',
        });
        expect(row.classList.contains('motion-pulse-once')).toBe(false);
    });

    it('renders in Arabic', () => {
        wrapper = mount(ToFixPanel, {
            props: {
                items: [
                    {
                        target: 'file-a',
                        name: 'contrat.pdf',
                        slot: null,
                        kind: 'file',
                    },
                ],
                allSent: false,
                lastUploadAt: null,
            },
            global: { plugins: [i18n('ar')] },
        });

        expect(wrapper.text()).toContain('ملف مرفوض');
    });
});

describe('"Supprimer et remplacer"', () => {
    /** A slot's open dropzone, as RequirementSlotCard renders it. */
    function dropzoneOnPage(slotId: number): HTMLElement {
        const zone = document.createElement('div');
        zone.id = replacementDropzoneId(slotId);
        const button = document.createElement('button');
        zone.appendChild(button);
        document.body.appendChild(zone);

        return zone;
    }

    function flow(reduced: boolean, announce: (slot: number) => void) {
        return createRemovalFlow({
            url: (uuid) => `/files/${uuid}`,
            slotOf: () => 7,
            reload: (_only, after) => after(),
            reloadProps: ['mediaFiles'],
            focusSlot: (slot) => {
                focusReplacementDropzone(slot, reduced);
                announce(slot);
            },
            toast: { success: vi.fn(), info: vi.fn(), error: vi.fn() },
            t: (key) => key,
        });
    }

    it('confirm → remove → the dropzone gets the focus, one pulse, an announcement', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () => new Response('{"removed":true}', { status: 200 }),
            ),
        );
        const zone = dropzoneOnPage(7);
        const announce = vi.fn();

        const outcome = await flow(false, announce).remove('f1', true);

        expect(outcome).toEqual({ kind: 'removed' });
        expect(document.activeElement).toBe(zone.querySelector('button'));
        expect(zone.classList.contains('motion-pulse-once')).toBe(true);
        expect(announce).toHaveBeenCalledWith(7);
    });

    it('under reduced motion: focus, but no pulse', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response('{}', { status: 200 })),
        );
        const zone = dropzoneOnPage(7);

        await flow(true, () => undefined).remove('f1', true);

        expect(document.activeElement).toBe(zone.querySelector('button'));
        expect(zone.classList.contains('motion-pulse-once')).toBe(false);
    });

    it('the dialog: file name, reason recap, the exact body, Esc blocked while busy', async () => {
        wrapper = mount(RejectedFileCallout, {
            props: {
                fileName: 'contrat.pdf',
                reason: REASON_A,
                reviewedAt: null,
                canRemove: true,
                canReplace: true,
            },
            global: { plugins: [i18n()] },
            attachTo: document.body,
        });

        await wrapper.find('[data-testid="rejected-replace"]').trigger('click');
        await settle();
        const dialog = document.querySelector(
            '[data-testid="removal-dialog"]',
        )!;
        expect(dialog.textContent).toContain('contrat.pdf');
        expect(dialog.textContent).toContain(REASON_A);
        expect(
            dialog.querySelector('[data-testid="removal-body"]')?.textContent,
        ).toContain(
            'Le fichier actuel sera supprimé définitivement. Vous pourrez ensuite téléverser le nouveau.',
        );
        const confirm = () =>
            document.querySelector<HTMLButtonElement>(
                '[data-testid="removal-confirm"]',
            )!;
        expect(confirm().textContent).toContain('Supprimer et remplacer');

        confirm().click();
        await wrapper.setProps({ busy: true });
        await settle();
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await settle();

        expect(wrapper.emitted('replace')).toHaveLength(1);
        expect(
            document.querySelector('[data-testid="removal-dialog"]'),
        ).not.toBeNull();
        expect(confirm().disabled).toBe(true);
    });
});

describe('the open slot on a returned deposit', () => {
    function slot(open: number) {
        wrapper = mount(RequirementSlotCard, {
            props: {
                requirement,
                position: 1,
                oeuvreId: 1,
                entries: [],
                quota: { used_bytes: 0, limit_bytes: 100 },
                editable: true,
                reviewMode: true,
                replacementOpen: open,
                reminder: REASON_A,
                reviewState: open > 0 ? 'to_fix' : null,
            } as never,
            global: { plugins: [i18n()] },
        });

        return wrapper;
    }

    it('reads "Remplacement attendu", with formats, size and the reason, focusable by id', () => {
        const w = slot(1);

        expect(w.find('#replacement-slot-7').exists()).toBe(true);
        expect(w.find('[data-testid="replacement-expected"]').text()).toBe(
            'Remplacement attendu',
        );
        const helper = w.find('[data-testid="replacement-requirement"]').text();
        expect(helper).toContain('PDF');
        expect(helper).toMatch(/Mo|MB/);
        expect(w.find('[data-testid="replacement-reminder"]').text()).toContain(
            REASON_A,
        );
    });

    it('with nothing to replace, it is the ordinary dropzone', () => {
        const w = slot(0);

        expect(w.find('input[type="file"]').exists()).toBe(true);
        expect(w.find('[data-testid="replacement-expected"]').exists()).toBe(
            false,
        );
        expect(w.find('#replacement-slot-7').exists()).toBe(false);
    });

    it('a wrong type or size is refused in the browser and never queued', async () => {
        wrapper = mount(Dropzone, {
            props: { oeuvreId: 1, requirement, replacement: true },
            global: { plugins: [i18n()] },
        });
        const queue = useUploadQueue();
        const before = queue.files.value.length;
        const input = wrapper.find('input[type="file"]');
        Object.defineProperty(input.element, 'files', {
            value: [
                new File(['x'], 'photo.png', { type: 'image/png' }),
                new File(['x'.repeat(2 * 1024 * 1024)], 'gros.pdf', {
                    type: 'application/pdf',
                }),
            ],
            configurable: true,
        });

        await input.trigger('change');

        expect(queue.files.value.length).toBe(before);
        expect(wrapper.text()).toContain('photo.png');
        expect(wrapper.text()).toContain('gros.pdf');
    });
});

describe('"Soumettre à nouveau"', () => {
    const blocker = (name: string) => ({
        code: 'file_rejected',
        params: { name, reason: REASON_A },
        message: '',
    });

    function area(blockers: ReturnType<typeof blocker>[], locale = 'fr') {
        wrapper = mount(SubmitArea, {
            props: {
                submission: {
                    can_submit: blockers.length === 0,
                    blockers,
                    advisories: [],
                    is_open: true,
                },
                status: 'rejected',
                editable: true,
                reasonText: (r: { params: Record<string, unknown> }) =>
                    String(r.params.name),
            },
            global: { plugins: [i18n(locale)] },
        });

        return wrapper;
    }

    it('is disabled with the reason while a file is still rejected', () => {
        const w = area([blocker('a.pdf'), blocker('b.pdf')]);

        const button = w.find('[data-testid="submit-disabled"]');
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.text()).toContain('Soumettre à nouveau');
        expect(button.attributes('aria-describedby')).toBe(
            'submit-blocked-reason',
        );
        expect(w.find('#submit-blocked-reason').text()).toBe(
            '2 fichiers rejetés doivent être remplacés',
        );
        // The list names each file too.
        expect(w.text()).toContain('a.pdf');
        expect(w.text()).toContain('b.pdf');
    });

    it('is enabled once nothing is rejected', async () => {
        const w = area([]);

        expect(w.find('[data-testid="submit-disabled"]').exists()).toBe(false);
        const button = w
            .findAll('button')
            .find((b) => b.text().includes('Soumettre à nouveau'))!;
        expect(button.attributes('disabled')).toBeUndefined();
        await button.trigger('click');
        expect(w.emitted('submit')).toHaveLength(1);
    });

    it('renders in Arabic', () => {
        const w = area([blocker('a.pdf')], 'ar');

        expect(w.text()).toContain('إعادة الإرسال');
        expect(w.find('#submit-blocked-reason').text()).toContain('مرفوض');
    });
});

describe('the breadcrumb', () => {
    it.each([
        ['fr', 'Oeuvres'],
        ['ar', 'الأعمال'],
        ['en', 'Works'],
    ])('is localized (%s)', (locale, label) => {
        wrapper = mount(Breadcrumbs, {
            props: {
                breadcrumbs: [
                    { title: 'sidebar.nav.oeuvres', href: '/author/oeuvres' },
                    { title: 'Mon oeuvre', href: '/author/oeuvres/x' },
                ],
            },
            global: { plugins: [i18n(locale)] },
        });

        expect(wrapper.text()).toContain(label);
        expect(wrapper.text()).not.toContain('sidebar.nav.oeuvres');
        // A plain title (the oeuvre's own) is shown as given.
        expect(wrapper.text()).toContain('Mon oeuvre');
    });
});

describe('RTL and dark mode', () => {
    it('ar, dark: the rejected row keeps its start-side bar, tokens and words', () => {
        document.documentElement.setAttribute('dir', 'rtl');
        document.documentElement.classList.add('dark');

        const w = card(
            media({
                review_status: 'rejected',
                review_reason: REASON_A,
                can_remove: true,
            }),
            'ar',
        );

        // Logical properties: the bar is on the start side in both directions.
        expect(w.find('[data-testid="review-accent"]').classes()).toContain(
            'start-0',
        );
        expect(w.find('[data-testid="review-status-badge"]').text()).toContain(
            ar['oeuvres.replacement.status.rejected'],
        );
        expect(w.text()).toContain(ar['oeuvres.replacement.reasonHeading']);
        expect(w.text()).toContain(ar['oeuvres.replacement.removeAndReplace']);
    });
});
