// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import type { Component } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';
import type { Checks } from './decisions';

const toast = vi.hoisted(() => ({
    success: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    error: vi.fn(),
}));

vi.mock('vue-sonner', () => ({ toast }));
vi.mock('@/routes/admin/oeuvres', () => ({
    review: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}/review` }),
    approve: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}/approve` }),
    reject: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}/reject` }),
}));

const { default: AcceptDialog } = await import('./AcceptDialog.vue');
const { default: RejectDialog } = await import('./RejectDialog.vue');
const { default: OeuvreDecisions } = await import('./OeuvreDecisions.vue');
const { default: ReviewTimeline } = await import('./ReviewTimeline.vue');
const { default: DepositStatusBanner } =
    await import('./DepositStatusBanner.vue');

const makeI18n = (locale = 'fr') =>
    createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'en',
        messages: { en, fr, ar },
        missingWarn: false,
        fallbackWarn: false,
    });

const OEUVRE = {
    uuid: '0199-abcd',
    label: 'Symphonie des Aurès',
    author: 'Lina Haddad',
    reference: '0199-abcd',
    status: 'under_review',
};
const CLEAN: Checks = { files: 3, pending: 0, blocked: 0, missing_required: 0 };
const VALID_REASON = 'Le contrat signé est illisible, merci de le redéposer.';

let wrapper: VueWrapper | null = null;

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

const panel = (): HTMLElement | null =>
    document.querySelector<HTMLElement>('[data-slot="app-dialog"]');

function pressEscape(): void {
    document.dispatchEvent(
        new KeyboardEvent('keydown', {
            key: 'Escape',
            bubbles: true,
            cancelable: true,
        }),
    );
}

/** A fetch whose reply the test releases when it chooses to. */
function deferredFetch() {
    let release!: (value: unknown) => void;
    const mock = vi.fn(
        () =>
            new Promise((resolve) => {
                release = resolve;
            }),
    );

    vi.stubGlobal('fetch', mock);

    return {
        mock,
        reply: (status: number, body: unknown) =>
            release({
                ok: status >= 200 && status < 300,
                status,
                json: async () => body,
            }),
    };
}

function immediateFetch(status: number, body: unknown) {
    const mock = vi.fn(async () => ({
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    }));

    vi.stubGlobal('fetch', mock);

    return mock;
}

function type(field: HTMLTextAreaElement, value: string): void {
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
}

beforeEach(() => {
    Object.values(toast).forEach((spy) => spy.mockClear());
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('AcceptDialog', () => {
    it('shows the checklist, the reference, the author and the file count', async () => {
        render(AcceptDialog, { open: true, oeuvre: OEUVRE, checks: CLEAN });
        await settle();

        const dialog = panel()!;

        expect(dialog.dataset.size).toBe('md');
        expect($('check-required')!.dataset.ok).toBe('true');
        expect($('check-ready')!.dataset.ok).toBe('true');
        expect(dialog.textContent).toContain(fr['review.accept.checkRequired']);
        expect(dialog.textContent).toContain(fr['review.accept.checkReady']);
        expect(dialog.textContent).toContain('Symphonie des Aurès');
        expect(dialog.textContent).toContain('0199-abcd');
        expect(dialog.textContent).toContain('Lina Haddad');
        expect($('accept-confirm')!.textContent).toContain('Accepter le dépôt');
        expect($<HTMLButtonElement>('accept-confirm')!.disabled).toBe(false);
        expect($('accept-problems')).toBeNull();
    });

    it('disables the button and says why when a file is still processing', async () => {
        render(AcceptDialog, {
            open: true,
            oeuvre: OEUVRE,
            checks: { ...CLEAN, pending: 2 },
        });
        await settle();

        expect($('check-ready')!.dataset.ok).toBe('false');
        expect($('check-ready')!.textContent).toContain(
            '2 fichiers en cours de traitement',
        );
        expect($('check-required')!.dataset.ok).toBe('true');
        expect($<HTMLButtonElement>('accept-confirm')!.disabled).toBe(true);
    });

    it('disables the button when a required document is missing, and never posts', async () => {
        const fetchMock = immediateFetch(200, {});

        render(AcceptDialog, {
            open: true,
            oeuvre: OEUVRE,
            checks: { ...CLEAN, missing_required: 1 },
        });
        await settle();

        expect($('check-required')!.dataset.ok).toBe('false');
        expect($('check-required')!.textContent).toContain(
            '1 document obligatoire manquant',
        );

        const button = $<HTMLButtonElement>('accept-confirm')!;

        expect(button.disabled).toBe(true);
        button.click();
        await settle();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('blocks Escape and the buttons while the request is in flight', async () => {
        const request = deferredFetch();
        const view = render(AcceptDialog, {
            open: true,
            oeuvre: OEUVRE,
            checks: CLEAN,
        });
        await settle();

        $('accept-confirm')!.click();
        await settle();

        expect(request.mock).toHaveBeenCalledTimes(1);
        expect(panel()!.getAttribute('aria-busy')).toBe('true');
        expect($<HTMLButtonElement>('accept-confirm')!.disabled).toBe(true);
        // The spinner replaces the icon while it waits.
        expect(
            $('accept-confirm')!.querySelector('[role="status"]'),
        ).not.toBeNull();

        pressEscape();
        await settle();

        expect(panel()).not.toBeNull();
        expect(view.emitted('update:open')).toBeUndefined();

        request.reply(200, { status: 'registered', replayed: false });
        await settle();

        expect(view.emitted('update:open')).toEqual([[false]]);
        expect(view.emitted('outcome')![0]).toEqual([
            { kind: 'done', status: 'registered', replayed: false },
        ]);
    });

    it('shows the server problem list on a 422, by name, and stays open', async () => {
        immediateFetch(422, {
            message: 'refused',
            errors: { approval: ['x'] },
            problems: [
                {
                    code: 'file_quarantined',
                    params: { name: 'maquette.wav' },
                    message: 'x',
                },
                {
                    code: 'slot_empty',
                    params: { name: "Justificatif d'exploitation" },
                    message: 'y',
                },
            ],
        });

        const view = render(AcceptDialog, {
            open: true,
            oeuvre: OEUVRE,
            checks: CLEAN,
        });
        await settle();

        $('accept-confirm')!.click();
        await settle();

        const problems = $('accept-problems')!;

        expect(problems.getAttribute('role')).toBe('alert');
        expect(problems.querySelectorAll('li')).toHaveLength(2);
        expect(problems.textContent).toContain('maquette.wav');
        expect(problems.textContent).toContain('quarantaine');
        expect(problems.textContent).toContain(
            "Document obligatoire manquant : Justificatif d'exploitation.",
        );
        expect($('check-required')!.dataset.ok).toBe('false');
        expect($('check-ready')!.dataset.ok).toBe('false');
        expect($<HTMLButtonElement>('accept-confirm')!.disabled).toBe(true);
        expect(view.emitted('outcome')).toBeUndefined();
        expect(view.emitted('update:open')).toBeUndefined();
    });
});

describe('RejectDialog', () => {
    const field = () => $<HTMLTextAreaElement>('reject-reason')!;
    const confirm = () => $<HTMLButtonElement>('reject-confirm')!;

    async function openReject(locale = 'fr'): Promise<VueWrapper> {
        // Mounted closed, then opened: the dialog resets itself on open.
        const view = render(
            RejectDialog,
            { open: false, oeuvre: OEUVRE },
            locale,
        );

        await view.setProps({ open: true });
        await settle();

        return view;
    }

    it('is a large dialog whose confirm is disabled until the reason is valid', async () => {
        await openReject();

        expect(panel()!.dataset.size).toBe('lg');
        expect(confirm().disabled).toBe(true);
        expect($('reject-counter')!.textContent).toContain('0 / 2000');
        expect(panel()!.textContent).toContain('Entre 20 et 2000 caractères.');
    });

    it('validates the minimum, inline, and updates the counter as you type', async () => {
        await openReject();

        type(field(), 'Trop court');
        field().dispatchEvent(new Event('blur'));
        await settle();

        expect($('reject-counter')!.textContent).toContain('10 / 2000');
        expect(confirm().disabled).toBe(true);
        expect($('reject-error')!.textContent).toContain(
            'Encore 10 caractères au minimum.',
        );
        expect(field().getAttribute('aria-invalid')).toBe('true');

        type(field(), VALID_REASON);
        await settle();

        expect($('reject-counter')!.textContent).toContain(
            `${VALID_REASON.length} / 2000`,
        );
        expect($('reject-error')).toBeNull();
        expect(confirm().disabled).toBe(false);
    });

    it('counts trimmed, so padding cannot reach the minimum', async () => {
        await openReject();

        type(field(), `     ${'a'.repeat(19)}        `);
        await settle();

        expect($('reject-counter')!.textContent).toContain('19 / 2000');
        expect(confirm().disabled).toBe(true);
    });

    it('validates the maximum', async () => {
        await openReject();

        type(field(), 'a'.repeat(2003));
        await settle();

        expect(confirm().disabled).toBe(true);
        expect($('reject-error')!.textContent).toContain(
            '3 caractères en trop.',
        );
    });

    it('has the four quick reasons in ar, fr and en', async () => {
        for (const [locale, bundle] of [
            ['fr', fr],
            ['en', en],
            ['ar', ar],
        ] as const) {
            await openReject(locale);

            const chips = [
                ...document.querySelectorAll<HTMLElement>('[data-chip]'),
            ].map((chip) => chip.textContent?.trim());

            expect(chips).toEqual([
                bundle['review.reject.chips.missingDocument'],
                bundle['review.reject.chips.unreadableFile'],
                bundle['review.reject.chips.wrongInformation'],
                bundle['review.reject.chips.wrongFormat'],
            ]);

            wrapper?.unmount();
            wrapper = null;
            document.body.innerHTML = '';
        }

        expect(fr['review.reject.chips.missingDocument']).toBe(
            'Document obligatoire manquant',
        );
    });

    it('a chip inserts its text at the cursor and the counter follows', async () => {
        await openReject();

        type(field(), 'Bonjour. Merci.');
        await settle();
        field().setSelectionRange(8, 8);

        document
            .querySelector<HTMLElement>('[data-chip="unreadableFile"]')!
            .click();
        await settle();

        expect(field().value).toBe(
            'Bonjour. Fichier illisible ou corrompu Merci.',
        );
        expect($('reject-counter')!.textContent).toContain(
            `${field().value.length} / 2000`,
        );
        expect(field().selectionStart).toBe(
            'Bonjour. Fichier illisible ou corrompu'.length,
        );
        expect(confirm().disabled).toBe(false);

        // A chip alone is a valid starting point; a second one appends.
        type(field(), '');
        await settle();
        document
            .querySelector<HTMLElement>('[data-chip="wrongFormat"]')!
            .click();
        await settle();
        expect(field().value).toBe('Format non conforme');
        expect(confirm().disabled).toBe(true); // 19 characters
    });

    it('posts the trimmed reason and blocks Escape while busy', async () => {
        const request = deferredFetch();
        const view = await openReject();

        type(field(), `  ${VALID_REASON}  `);
        await settle();
        panel()!
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { bubbles: true, cancelable: true }),
            );
        await settle();

        const [url, init] = request.mock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];

        expect(url).toBe('/admin/oeuvres/0199-abcd/reject');
        expect(JSON.parse(String(init.body))).toEqual({ reason: VALID_REASON });
        expect(confirm().disabled).toBe(true);
        expect(field().disabled).toBe(true);

        pressEscape();
        await settle();
        expect(panel()).not.toBeNull();

        request.reply(200, { status: 'rejected', replayed: false });
        await settle();

        expect(view.emitted('outcome')![0]).toEqual([
            { kind: 'done', status: 'rejected', replayed: false },
        ]);
        expect(view.emitted('update:open')).toEqual([[false]]);
    });

    it('shows a server validation error on the field and stays open', async () => {
        immediateFetch(422, {
            message: 'x',
            errors: { reason: ['The reason field must be at least 20.'] },
        });
        const view = await openReject();

        type(field(), VALID_REASON);
        await settle();
        panel()!
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { bubbles: true, cancelable: true }),
            );
        await settle();

        expect($('reject-error')!.textContent).toContain(
            'The reason field must be at least 20.',
        );
        expect(view.emitted('outcome')).toBeUndefined();
    });
});

describe('OeuvreDecisions', () => {
    const base = {
        oeuvre: OEUVRE,
        holder: { uuid: 'u1', name: 'Moi', is_you: true },
        checks: CLEAN,
        canDecide: true,
    };

    it('offers only the actions valid for the status', async () => {
        render(OeuvreDecisions, {
            ...base,
            oeuvre: { ...OEUVRE, status: 'submitted' },
            holder: null,
        });
        await settle();

        expect($('action-start')).not.toBeNull();
        expect($('action-accept')).toBeNull();
        expect($('action-reject')).toBeNull();

        wrapper!.unmount();
        render(OeuvreDecisions, base);
        await settle();

        expect($('action-start')).toBeNull();
        expect($('action-accept')).not.toBeNull();
        expect($('action-reject')).not.toBeNull();
        expect($('holder-notice')!.textContent).toContain(
            fr['review.held.you'],
        );

        wrapper!.unmount();
        render(OeuvreDecisions, {
            ...base,
            oeuvre: { ...OEUVRE, status: 'registered' },
        });
        await settle();

        expect($('action-start')).toBeNull();
        expect($('action-accept')).toBeNull();
        expect($('action-reject')).toBeNull();
        expect($('action-take-over')).toBeNull();
    });

    it('keeps a visible reason next to a disabled Accept', async () => {
        render(OeuvreDecisions, {
            ...base,
            checks: { files: 4, pending: 2, blocked: 0, missing_required: 1 },
        });
        await settle();

        expect($<HTMLButtonElement>('action-accept')!.disabled).toBe(true);
        expect($('accept-hint')!.textContent).toContain(
            '2 fichiers en cours de traitement',
        );
        expect($('accept-hint')!.textContent).toContain(
            '1 document obligatoire manquant',
        );
        // Reject stays available: an incomplete deposit can be sent back.
        expect($<HTMLButtonElement>('action-reject')!.disabled).toBe(false);
    });

    it('a deposit held by another officer shows who, and only a take-over behind a confirm', async () => {
        const fetchMock = immediateFetch(200, {
            status: 'under_review',
            replayed: false,
        });
        const view = render(OeuvreDecisions, {
            ...base,
            holder: { uuid: 'u2', name: 'Karim', is_you: false },
        });
        await settle();

        expect($('holder-notice')!.textContent).toContain(
            "En cours d'examen par Karim",
        );
        expect($('action-accept')).toBeNull();
        expect($('action-reject')).toBeNull();

        $('action-take-over')!.click();
        await settle();

        // Nothing is sent until the officer confirms.
        expect(fetchMock).not.toHaveBeenCalled();
        expect($('take-over-dialog')!.textContent).toContain('Karim');

        $('take-over-confirm')!.click();
        await settle();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];

        expect(url).toBe('/admin/oeuvres/0199-abcd/review');
        expect(JSON.parse(String(init.body))).toEqual({ take_over: true });
        expect(toast.success).toHaveBeenCalledWith(
            fr['review.toast.takenOver'],
        );
        expect(view.emitted('changed')).toHaveLength(1);
    });

    it('"Commencer l\'examen" asks in a popover, then posts and reports', async () => {
        const fetchMock = immediateFetch(200, {
            status: 'under_review',
            replayed: false,
        });
        const view = render(OeuvreDecisions, {
            ...base,
            oeuvre: { ...OEUVRE, status: 'submitted' },
            holder: null,
        });
        await settle();

        $('action-start')!.click();
        await settle();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(panel()).toBeNull(); // a popover, not a dialog
        expect($('start-confirm')!.textContent).toContain(
            fr['review.start.confirmTitle'],
        );

        $('start-confirm-button')!.click();
        await settle();

        const [url, init] = fetchMock.mock.calls[0] as unknown as [
            string,
            RequestInit,
        ];

        expect(url).toBe('/admin/oeuvres/0199-abcd/review');
        expect(JSON.parse(String(init.body))).toEqual({});
        expect(toast.success).toHaveBeenCalledWith(fr['review.toast.started']);
        expect(view.emitted('changed')).toHaveLength(1);
    });

    it('a 409 shows "Déjà traité par {admin} le {date}" and asks the page to refresh', async () => {
        immediateFetch(409, {
            message: 'Amina has already decided on this deposit.',
            conflict: {
                kind: 'decided',
                by: 'Amina',
                at: '2026-10-12T09:30:00+00:00',
                status: 'registered',
            },
        });
        const view = render(OeuvreDecisions, base);
        await settle();

        $('action-accept')!.click();
        await settle();
        $('accept-confirm')!.click();
        await settle();

        expect(toast.error).toHaveBeenCalledTimes(1);
        expect(toast.error.mock.calls[0][0]).toBe(
            'Déjà traité par Amina le 12 oct. 2026',
        );
        expect(toast.success).not.toHaveBeenCalled();
        expect(view.emitted('changed')).toHaveLength(1);
        expect(panel()).toBeNull();
    });

    it('a 409 "held" names the officer reviewing it', async () => {
        immediateFetch(409, {
            message: 'x',
            conflict: {
                kind: 'held',
                by: 'Karim',
                at: null,
                status: 'under_review',
            },
        });
        const view = render(OeuvreDecisions, base);
        await settle();

        $('action-accept')!.click();
        await settle();
        $('accept-confirm')!.click();
        await settle();

        expect(toast.warning).toHaveBeenCalledWith(
            "En cours d'examen par Karim",
        );
        expect(view.emitted('changed')).toHaveLength(1);
    });

    it('a success closes the dialog, toasts and asks for a reload; a replay says so', async () => {
        immediateFetch(200, { status: 'registered', replayed: false });
        const view = render(OeuvreDecisions, base);
        await settle();

        $('action-accept')!.click();
        await settle();
        $('accept-confirm')!.click();
        await settle();

        expect(toast.success).toHaveBeenCalledWith(fr['review.toast.accepted']);
        expect(view.emitted('changed')).toHaveLength(1);
        expect(panel()).toBeNull();

        immediateFetch(200, { status: 'registered', replayed: true });
        $('action-accept')!.click();
        await settle();
        $('accept-confirm')!.click();
        await settle();

        expect(toast.info).toHaveBeenCalledWith(fr['review.toast.replayed']);
        expect(toast.success).toHaveBeenCalledTimes(1);
    });

    it('a network failure toasts and leaves the page alone', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new TypeError('offline');
            }),
        );
        const view = render(OeuvreDecisions, base);
        await settle();

        $('action-accept')!.click();
        await settle();
        $('accept-confirm')!.click();
        await settle();

        expect(toast.error).toHaveBeenCalledWith(fr['review.toast.failed']);
        expect(view.emitted('changed')).toBeUndefined();
    });

    it('the row menu renders nothing when no transition is valid', async () => {
        render(OeuvreDecisions, {
            ...base,
            variant: 'menu',
            oeuvre: { ...OEUVRE, status: 'registered' },
        });
        await settle();

        expect($('oeuvre-decisions')).toBeNull();
        expect($('decision-menu')).toBeNull();

        wrapper!.unmount();
        render(OeuvreDecisions, { ...base, variant: 'menu' });
        await settle();

        expect($('decision-menu')!.getAttribute('aria-label')).toBe(
            fr['review.actions.menu'],
        );
    });
});

describe('ReviewTimeline', () => {
    const reviews = [
        {
            uuid: 'r1',
            from_status: 'draft',
            to_status: 'submitted',
            reason: null,
            created_at: '2026-10-01T08:00:00+00:00',
            actor: 'Lina Haddad',
        },
        {
            uuid: 'r2',
            from_status: 'submitted',
            to_status: 'under_review',
            reason: null,
            created_at: '2026-10-02T08:00:00+00:00',
            actor: 'Amina',
        },
        {
            uuid: 'r3',
            from_status: 'under_review',
            to_status: 'rejected',
            reason: VALID_REASON,
            created_at: '2026-10-03T08:00:00+00:00',
            actor: 'Amina',
        },
    ];

    const entries = () => [
        ...document.querySelectorAll<HTMLElement>(
            '[data-testid="timeline-entry"]',
        ),
    ];

    it('renders newest first, each with its from and to badges', async () => {
        render(ReviewTimeline, { reviews });
        await settle();

        expect(entries().map((entry) => entry.dataset.to)).toEqual([
            'rejected',
            'under_review',
            'submitted',
        ]);

        const [newest, , oldest] = entries();
        const badges = (entry: HTMLElement) =>
            [...entry.querySelectorAll('[data-slot="badge"]')].map((badge) =>
                badge.textContent?.trim(),
            );

        expect(badges(newest)).toEqual([
            fr['oeuvres.status.under_review'],
            fr['oeuvres.status.rejected'],
        ]);
        expect(badges(oldest)).toEqual([
            fr['oeuvres.status.draft'],
            fr['oeuvres.status.submitted'],
        ]);
    });

    it('shows the reviewer, the reason and the exact date in a tooltip', async () => {
        render(ReviewTimeline, { reviews });
        await settle();

        const newest = entries()[0];
        const time = newest.querySelector('time')!;

        expect(
            newest.querySelector('[data-testid="timeline-actor"]')!.textContent,
        ).toBe('Amina');
        expect(
            newest.querySelector('[data-testid="timeline-reason"]')!
                .textContent,
        ).toContain(VALID_REASON);
        expect(time.getAttribute('datetime')).toBe('2026-10-03T08:00:00+00:00');
        expect(time.getAttribute('title')).toContain('3 octobre 2026');
        expect(time.textContent?.trim()).not.toBe('');
        expect(
            entries()[1].querySelector('[data-testid="timeline-reason"]'),
        ).toBeNull();
    });

    it('signs every office decision "ONDA" for the author, and names nobody', async () => {
        // What the author's page is really sent: no `actor` key at all.
        const anonymous = reviews.map((review) => ({
            uuid: review.uuid,
            from_status: review.from_status,
            to_status: review.to_status,
            reason: review.reason,
            created_at: review.created_at,
        }));

        render(ReviewTimeline, { reviews: anonymous, anonymous: true });
        await settle();

        const signatures = entries().map(
            (entry) =>
                entry.querySelector('[data-testid="timeline-actor"]')
                    ?.textContent ?? null,
        );

        // rejected and under_review are ONDA's; submitting is the author's.
        expect(signatures).toEqual(['ONDA', 'ONDA', null]);
        expect(document.body.textContent).not.toContain('Amina');
        expect(document.body.textContent).toContain(VALID_REASON);
    });

    it('never shows a name passed by mistake in anonymous mode', async () => {
        render(ReviewTimeline, { reviews, anonymous: true });
        await settle();

        expect(document.body.textContent).not.toContain('Amina');
    });

    it('says so when there is no decision yet', async () => {
        render(ReviewTimeline, { reviews: [] });
        await settle();

        expect(document.body.textContent).toContain(
            fr['review.timeline.empty'],
        );
        expect(entries()).toHaveLength(0);
    });
});

describe('DepositStatusBanner', () => {
    const rejected = [
        {
            uuid: 'r0',
            from_status: 'under_review',
            to_status: 'rejected',
            reason: 'Premier motif, celui de la première fois.',
            created_at: '2026-09-01T08:00:00+00:00',
        },
        {
            uuid: 'r1',
            from_status: 'rejected',
            to_status: 'submitted',
            reason: null,
            created_at: '2026-09-10T08:00:00+00:00',
        },
        {
            uuid: 'r2',
            from_status: 'under_review',
            to_status: 'rejected',
            reason: VALID_REASON,
            created_at: '2026-10-03T08:00:00+00:00',
        },
    ];

    it('rejected: the latest reason, the decision date and the resubmit action', async () => {
        const view = render(DepositStatusBanner, {
            status: 'rejected',
            registeredAt: null,
            reviews: rejected,
            editable: true,
        });
        await settle();

        const banner = $('deposit-status-banner')!;

        expect(banner.getAttribute('role')).toBe('alert');
        expect($('rejection-reason')!.textContent).toContain(VALID_REASON);
        expect(banner.textContent).not.toContain('Premier motif');
        expect($('rejection-date')!.textContent).toContain('3 oct. 2026');
        expect($('resubmit-action')!.textContent).toContain(
            'Modifier et soumettre à nouveau',
        );

        $('resubmit-action')!.click();
        expect(view.emitted('resubmit')).toHaveLength(1);
    });

    it('under review: "En cours d\'examen par l\'ONDA", no action', async () => {
        render(DepositStatusBanner, {
            status: 'under_review',
            registeredAt: null,
            reviews: [],
            editable: false,
        });
        await settle();

        expect($('deposit-status-banner')!.textContent).toContain(
            "En cours d'examen par l'ONDA",
        );
        expect($('deposit-status-banner')!.getAttribute('role')).toBe('status');
        expect($('resubmit-action')).toBeNull();
    });

    it('registered: a success banner with the registration date', async () => {
        render(DepositStatusBanner, {
            status: 'registered',
            registeredAt: '2026-10-12T09:30:00+00:00',
            reviews: [],
            editable: false,
        });
        await settle();

        const banner = $('deposit-status-banner')!;

        expect(banner.dataset.status).toBe('registered');
        expect(banner.textContent).toContain('Dépôt enregistré');
        expect(banner.textContent).toContain('12 oct. 2026');
    });

    it('a draft has no banner', async () => {
        render(DepositStatusBanner, {
            status: 'draft',
            registeredAt: null,
            reviews: [],
            editable: true,
        });
        await settle();

        expect($('deposit-status-banner')).toBeNull();
    });

    it('renders in Arabic and English', async () => {
        for (const [locale, bundle] of [
            ['ar', ar],
            ['en', en],
        ] as const) {
            render(
                DepositStatusBanner,
                {
                    status: 'rejected',
                    registeredAt: null,
                    reviews: rejected,
                    editable: true,
                },
                locale,
            );
            await settle();

            expect($('deposit-status-banner')!.textContent).toContain(
                bundle['review.banner.rejected.title'],
            );
            expect($('resubmit-action')!.textContent).toContain(
                bundle['review.banner.rejected.action'],
            );

            wrapper?.unmount();
            wrapper = null;
            document.body.innerHTML = '';
        }
    });
});
