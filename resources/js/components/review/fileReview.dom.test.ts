// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';
import type { Component } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';
import type { ConsultationDescriptor } from '@/types/consultation';

const toast = vi.hoisted(() => ({
    success: vi.fn(),
    info: vi.fn(),
    error: vi.fn(),
}));
const inertia = vi.hoisted(() => ({ visit: vi.fn(), reload: vi.fn() }));

vi.mock('vue-sonner', () => ({ toast }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: inertia,
}));
vi.mock('@/routes/admin', () => ({ dashboard: () => ({ url: '/admin' }) }));
vi.mock('@/routes/admin/oeuvres', () => ({
    index: () => ({ url: '/admin/oeuvres' }),
    show: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}` }),
}));
vi.mock('@/routes/admin/oeuvres/files', () => ({
    decide: (args: { oeuvre: string; mediaFile: string }) => ({
        url: `/admin/oeuvres/${args.oeuvre}/files/${args.mediaFile}/review`,
    }),
    review: (args: { oeuvre: string; mediaFile: string }) => ({
        url: `/admin/oeuvres/${args.oeuvre}/files/${args.mediaFile}/review`,
    }),
}));
// The page test is about its own header and notices, not the viewer.
vi.mock('@/components/consult/FileReviewLayout.vue', () => ({
    default: defineComponent({
        setup(_props, { slots }) {
            return () =>
                h('div', [
                    slots['header-actions']?.(),
                    slots['before-viewer']?.(),
                ]);
        },
    }),
}));

const { default: FileDecisionActions } =
    await import('./FileDecisionActions.vue');
const { default: FileRejectDialog } = await import('./FileRejectDialog.vue');
const { nextToReview } = await import('./fileReview');
const { default: FileReviewPage } =
    await import('@/pages/admin/oeuvres/FileReview.vue');

const REASON = 'Le document est illisible, merci de le déposer à nouveau.';

let wrapper: VueWrapper | null = null;
let fetchMock: ReturnType<typeof vi.fn>;

const makeI18n = (locale = 'fr') =>
    createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'en',
        messages: { en, fr, ar },
        missingWarn: false,
        fallbackWarn: false,
    });

function render(
    component: Component,
    props: Record<string, unknown>,
    locale = 'fr',
): VueWrapper {
    wrapper = mount(component, {
        props,
        global: { plugins: [makeI18n(locale)] },
        attachTo: document.body,
    });

    return wrapper;
}

const settle = async (): Promise<void> => {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 30));
    await nextTick();
};

const $ = <T extends HTMLElement = HTMLElement>(testId: string): T | null =>
    document.querySelector<T>(`[data-testid="${testId}"]`);

function respond(status: number, body: Record<string, unknown>): void {
    fetchMock = vi.fn(async () => ({
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    }));
    vi.stubGlobal('fetch', fetchMock);
}

const file = (over: Record<string, unknown> = {}) => ({
    uuid: 'f1',
    original_name: 'contrat.pdf',
    review_status: 'pending',
    review_reason: null,
    ...over,
});

beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=tok%3D';
    toast.success.mockClear();
    toast.info.mockClear();
    toast.error.mockClear();
    inertia.reload.mockClear();
    inertia.visit.mockClear();
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('FileDecisionActions', () => {
    it('a pending file offers exactly the allowed actions', () => {
        render(FileDecisionActions, {
            oeuvre: 'o1',
            file: file(),
            allowed: { actions: ['reject'], reason: null },
        });

        expect($('file-reject')).not.toBeNull();
        expect($('file-accept')).toBeNull();
        expect($('file-revise')).toBeNull();
    });

    it('with nothing allowed, greys the actions and keeps the reason visible', () => {
        render(FileDecisionActions, {
            oeuvre: 'o1',
            file: file(),
            allowed: { actions: [], reason: 'not_holder' },
        });

        const accept = $<HTMLButtonElement>('file-accept')!;

        expect(accept.disabled).toBe(true);
        expect($<HTMLButtonElement>('file-reject')!.disabled).toBe(true);
        expect($('file-actions-reason')!.textContent).toContain(
            'Un autre agent examine ce dépôt.',
        );
        expect(accept.getAttribute('aria-describedby')).toBe(
            $('file-actions-reason')!.id,
        );
    });

    it('a decided deposit is read-only: the reason, no buttons', () => {
        render(FileDecisionActions, {
            oeuvre: 'o1',
            file: file({ review_status: 'accepted' }),
            allowed: { actions: [], reason: 'oeuvre_decided' },
        });

        expect($('file-accept')).toBeNull();
        expect($('file-revise')).toBeNull();
        expect($('file-actions-reason')!.dataset.reason).toBe('oeuvre_decided');
    });

    it('a decided file offers "Modifier la décision" with the server\'s choices', async () => {
        render(FileDecisionActions, {
            oeuvre: 'o1',
            file: file({ review_status: 'rejected', review_reason: REASON }),
            allowed: { actions: ['accept', 'reject', 'reset'], reason: null },
        });

        expect($('file-accept')).toBeNull();
        $('file-revise')!.click();
        await settle();

        expect($('file-revise-accept')).not.toBeNull();
        expect($('file-revise-reject')!.textContent).toContain(
            'Modifier le motif',
        );
        expect($('file-revise-reset')).not.toBeNull();
    });

    it('accept goes through the confirm popover, then posts and emits decided', async () => {
        respond(200, { reviewStatus: 'accepted', replayed: false });
        const w = render(FileDecisionActions, {
            oeuvre: 'o1',
            file: file(),
            allowed: { actions: ['accept', 'reject'], reason: null },
        });

        $('file-accept')!.click();
        await settle();
        expect($('file-accept-popover')!.textContent).toContain(
            'Accepter ce fichier ?',
        );
        expect(fetchMock).not.toHaveBeenCalled();

        $('file-accept-confirm')!.click();
        await settle();

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/admin/oeuvres/o1/files/f1/review');
        expect(JSON.parse(String(init.body))).toEqual({ decision: 'accepted' });
        expect(toast.success).toHaveBeenCalledWith('Fichier accepté.');
        expect(w.emitted('decided')).toEqual([['accepted']]);
    });

    it('409 held names the holder and asks the page to refetch', async () => {
        respond(409, {
            message: 'held',
            conflict: {
                kind: 'held',
                by: 'Amina',
                at: null,
                status: 'under_review',
            },
        });
        const w = render(FileDecisionActions, {
            oeuvre: 'o1',
            file: file({ review_status: 'accepted' }),
            allowed: { actions: ['reject', 'reset'], reason: null },
        });

        $('file-revise')!.click();
        await settle();
        $('file-revise-reset')!.click();
        await settle();

        expect($('file-decision-notice')!.textContent).toContain(
            "En cours d'examen par Amina",
        );
        expect(w.emitted('stale')).toHaveLength(1);
        expect(w.emitted('decided')).toBeUndefined();
    });

    it('409 decided says who and when', async () => {
        respond(409, {
            message: 'decided',
            conflict: {
                kind: 'decided',
                by: 'Karim',
                at: '2026-10-17T09:00:00+00:00',
                status: 'registered',
            },
        });
        render(FileDecisionActions, {
            oeuvre: 'o1',
            file: file({ review_status: 'accepted' }),
            allowed: { actions: ['reset'], reason: null },
        });

        $('file-revise')!.click();
        await settle();
        $('file-revise-reset')!.click();
        await settle();

        expect($('file-decision-notice')!.textContent).toMatch(
            /Déjà traité par Karim le .*2026/,
        );
    });

    it("422 shows the server's own message, never a generic one", async () => {
        const message =
            'Only a ready file can be accepted; this file is "failed".';
        respond(422, { message: 'invalid', errors: { decision: [message] } });
        render(FileDecisionActions, {
            oeuvre: 'o1',
            file: file({ review_status: 'rejected', review_reason: REASON }),
            allowed: { actions: ['accept', 'reset'], reason: null },
        });

        $('file-revise')!.click();
        await settle();
        $('file-revise-accept')!.click();
        await settle();

        expect($('file-decision-notice')!.textContent).toBe(message);
        expect(toast.error).toHaveBeenCalledWith(message);
    });
});

describe('FileRejectDialog', () => {
    const textarea = () => $<HTMLTextAreaElement>('reject-reason')!;
    const confirm = () => $<HTMLButtonElement>('file-reject-confirm')!;

    async function type(text: string): Promise<void> {
        textarea().value = text;
        textarea().dispatchEvent(new Event('input'));
        await settle();
    }

    it('enables the confirm only for 20 to 2000 characters', async () => {
        render(FileRejectDialog, { open: true, fileName: 'contrat.pdf' });
        await settle();

        expect(confirm().disabled).toBe(true);
        await type('a'.repeat(19));
        expect(confirm().disabled).toBe(true);
        await type('a'.repeat(20));
        expect(confirm().disabled).toBe(false);
        await type('a'.repeat(2001));
        expect(confirm().disabled).toBe(true);
        expect($('reject-counter')!.textContent).toContain('2001 / 2000');
    });

    it('a chip inserts its text at the cursor', async () => {
        render(FileRejectDialog, { open: true, fileName: 'contrat.pdf' });
        await settle();
        await type('Bonjour.');
        textarea().setSelectionRange(0, 0);

        document
            .querySelector<HTMLButtonElement>('[data-chip="duplicate"]')!
            .click();
        await settle();

        expect(textarea().value).toBe('Fichier en double Bonjour.');
    });

    it('offers the five file-level quick reasons', async () => {
        render(FileRejectDialog, { open: true, fileName: 'contrat.pdf' });
        await settle();

        const chips = [
            ...document.querySelectorAll<HTMLElement>('[data-chip]'),
        ].map((chip) => chip.dataset.chip);

        expect(chips).toEqual([
            'unreadable',
            'wrongType',
            'incomplete',
            'wrongFormat',
            'duplicate',
        ]);
    });

    it('while busy, Escape does not close it', async () => {
        const w = render(FileRejectDialog, {
            open: true,
            fileName: 'contrat.pdf',
            busy: true,
        });
        await settle();

        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await settle();

        expect(w.emitted('update:open')).toBeUndefined();
        expect(confirm().disabled).toBe(true);
    });

    it('emits the trimmed reason on confirm', async () => {
        const w = render(FileRejectDialog, {
            open: true,
            fileName: 'contrat.pdf',
        });
        await settle();
        await type(`   ${REASON}   `);

        confirm().click();
        await settle();

        expect(w.emitted('confirm')).toEqual([[REASON]]);
    });
});

describe('nextToReview', () => {
    const files = [
        { uuid: 'a', review_status: 'accepted' },
        { uuid: 'b', review_status: 'pending' },
        { uuid: 'c', review_status: 'rejected' },
        { uuid: 'd', review_status: 'pending' },
    ];

    it('skips decided files and wraps round', () => {
        expect(nextToReview(files, 'b')?.uuid).toBe('d');
        expect(nextToReview(files, 'd')?.uuid).toBe('b');
        expect(nextToReview(files, 'a')?.uuid).toBe('b');
    });

    it('is null when every other file has a decision', () => {
        expect(
            nextToReview(
                [
                    { uuid: 'a', review_status: 'accepted' },
                    { uuid: 'b', review_status: 'pending' },
                ],
                'b',
            ),
        ).toBeNull();
    });
});

describe('admin FileReview page', () => {
    const consultation = (
        over: Partial<ConsultationDescriptor> = {},
    ): ConsultationDescriptor => ({
        family: 'other',
        mode: 'derivative',
        original: null,
        status: 'ready',
        reason: null,
        notice: null,
        assets: [],
        pageCount: null,
        watermark: null,
        meta: {
            filename: 'contrat.pdf',
            sizeBytes: 2048,
            mime: 'application/pdf',
            sha256: null,
            depositedAt: null,
            slotLabel: null,
        },
        actions: { download: false },
        ...over,
    });

    const pageProps = (over: Record<string, unknown> = {}) => ({
        oeuvre: {
            uuid: 'o1',
            title: 'Symphonie',
            created_at: '2026-10-01T08:00:00+00:00',
            status: 'under_review',
        },
        file: {
            uuid: 'f1',
            original_name: 'contrat.doc',
            extension: 'doc',
            status: 'ready',
            size_bytes: 2048,
            mime: 'application/msword',
            sha256_plain: 'ab'.repeat(32),
            review_status: 'pending',
            review_reason: null,
            allowed: { actions: ['accept', 'reject'], reason: null },
        },
        siblings: [
            {
                uuid: 'f1',
                original_name: 'contrat.doc',
                slot: null,
                review_status: 'pending',
            },
            {
                uuid: 'f2',
                original_name: 'b.pdf',
                slot: null,
                review_status: 'accepted',
            },
            {
                uuid: 'f3',
                original_name: 'c.pdf',
                slot: null,
                review_status: 'pending',
            },
        ],
        consultation: consultation(),
        ...over,
    });

    it('the header actions follow `allowed`', () => {
        render(FileReviewPage, {
            ...pageProps(),
            file: {
                ...pageProps().file,
                allowed: { actions: [], reason: 'not_under_review' },
            },
        });

        expect($<HTMLButtonElement>('file-accept')!.disabled).toBe(true);
        expect($('file-actions-reason')!.dataset.reason).toBe(
            'not_under_review',
        );
        expect($('review-status-badge')!.dataset.status).toBe('pending');
    });

    it('"Suivant à examiner" skips decided files', () => {
        render(FileReviewPage, pageProps());

        $('next-to-review')!.click();

        expect(inertia.visit).toHaveBeenCalledWith(
            '/admin/oeuvres/o1/files/f3/review',
        );
    });

    it('a decision reloads only the file props, silently, and offers the next file', async () => {
        respond(200, { reviewStatus: 'accepted', replayed: false });
        render(FileReviewPage, pageProps());

        $('file-accept')!.click();
        await settle();
        $('file-accept-confirm')!.click();
        await settle();

        expect(inertia.reload).toHaveBeenCalledWith({
            only: ['file', 'siblings'],
            meta: { silent: true },
        });
        expect($('next-prompt')).not.toBeNull();

        $('next-prompt-go')!.click();
        expect(inertia.visit).toHaveBeenCalledWith(
            '/admin/oeuvres/o1/files/f3/review',
        );
    });

    it('an unsupported format still shows name, size, type and hash, and the decision', () => {
        render(FileReviewPage, {
            ...pageProps(),
            consultation: consultation({
                mode: 'unsupported',
                status: 'unsupported',
            }),
        });

        const alert = $('unsupported-alert')!;

        expect(alert.textContent).toContain(
            'Aperçu indisponible pour ce format',
        );
        expect(alert.textContent).toContain('contrat.doc');
        expect(alert.textContent).toContain('DOC');
        expect(alert.textContent).toContain('ab'.repeat(32));
        expect($('file-accept')).not.toBeNull();
    });

    it('renders in Arabic', () => {
        render(FileReviewPage, pageProps(), 'ar');

        expect($('next-to-review')!.textContent).toContain('التالي للفحص');
    });
});
