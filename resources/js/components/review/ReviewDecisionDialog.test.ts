// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';
import type { DecisionSummary } from './decisions';

const toast = vi.hoisted(() => ({
    success: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    error: vi.fn(),
}));

vi.mock('vue-sonner', () => ({ toast }));
vi.mock('@/routes/admin/oeuvres', () => ({
    show: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}` }),
    decision: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}/decision` }),
    review: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}/review` }),
    approve: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}/approve` }),
    reject: (uuid: string) => ({ url: `/admin/oeuvres/${uuid}/reject` }),
}));

const { default: ReviewDecisionDialog } =
    await import('./ReviewDecisionDialog.vue');

const UUID = '0199-abcd';
const DECISION = `GET /admin/oeuvres/${UUID}/decision`;
const VALID_REASON = 'Le contrat signé est illisible, merci de le redéposer.';

function summary(overrides: Partial<DecisionSummary> = {}): DecisionSummary {
    return {
        uuid: UUID,
        status: 'under_review',
        reference: UUID,
        title: 'Symphonie des Aurès',
        author: { name: 'Lina Haddad' },
        college: 'Musique',
        createdAt: '2026-10-01T10:00:00Z',
        submittedAt: '2026-10-02T10:00:00Z',
        holder: { id: 'me', name: 'Amina' },
        heldByMe: true,
        checklist: {
            noFiles: false,
            requiredMissing: [],
            filesNotReady: [],
            conditionalMissing: [],
        },
        allowed: ['approve', 'reject'],
        blocked: [],
        lastDecision: null,
        ...overrides,
    };
}

type Reply =
    | { status: number; body: unknown }
    | Promise<{ status: number; body: unknown }>;

/**
 * A tiny fake server: "METHOD /path" → replies, served in order (the last
 * one repeats). Every call is recorded with its parsed body.
 */
function server(routes: Record<string, Reply[]>) {
    const calls: { key: string; body: unknown }[] = [];
    const queues = Object.fromEntries(
        Object.entries(routes).map(([key, replies]) => [key, [...replies]]),
    );

    const mock = vi.fn(async (url: string, init: RequestInit = {}) => {
        const key = `${init.method ?? 'GET'} ${url}`;
        calls.push({
            key,
            body: init.body ? JSON.parse(String(init.body)) : undefined,
        });

        const queue = queues[key];

        if (!queue || queue.length === 0) {
            throw new Error(`unexpected ${key}`);
        }

        const reply = await (queue.length > 1 ? queue.shift()! : queue[0]);

        return {
            ok: reply.status >= 200 && reply.status < 300,
            status: reply.status,
            json: async () => reply.body,
        };
    });

    vi.stubGlobal('fetch', mock);

    return { mock, calls };
}

function deferred() {
    let release!: (value: { status: number; body: unknown }) => void;
    const promise = new Promise<{ status: number; body: unknown }>(
        (resolve) => {
            release = resolve;
        },
    );

    return { promise, release };
}

let wrapper: VueWrapper | null = null;

function open(locale = 'fr'): VueWrapper {
    const i18n = createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'en',
        messages: { en, fr, ar },
        missingWarn: false,
        fallbackWarn: false,
    });

    wrapper = mount(ReviewDecisionDialog, {
        props: { open: true, oeuvre: UUID },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

    return wrapper;
}

const settle = async (): Promise<void> => {
    for (let i = 0; i < 3; i++) {
        await nextTick();
        await new Promise((resolve) => setTimeout(resolve, 10));
    }
};

const $ = <T extends HTMLElement = HTMLElement>(testId: string): T | null =>
    document.querySelector<T>(`[data-testid="${testId}"]`);

const dialog = (): HTMLElement | null =>
    document.querySelector<HTMLElement>('[data-slot="app-dialog"]');

const closeRequested = (): boolean =>
    (wrapper?.emitted('update:open') ?? []).some(([value]) => value === false);

function type(field: HTMLTextAreaElement, value: string): void {
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
}

function pressEscape(): void {
    document.dispatchEvent(
        new KeyboardEvent('keydown', {
            key: 'Escape',
            bubbles: true,
            cancelable: true,
        }),
    );
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

describe('ReviewDecisionDialog — opening', () => {
    it('shows a skeleton, fetches the summary, then the header and the review screen', async () => {
        const reply = deferred();
        const { calls } = server({ [DECISION]: [reply.promise] });

        open();
        await settle();

        expect(dialog()?.dataset.size).toBe('lg');
        expect($('decision-loading')).not.toBeNull();
        expect(calls.map((call) => call.key)).toEqual([DECISION]);

        reply.release({
            status: 200,
            body: summary({
                checklist: {
                    noFiles: false,
                    requiredMissing: [],
                    filesNotReady: [],
                    conditionalMissing: ['Contrat de cession'],
                },
            }),
        });
        await settle();

        expect($('decision-loading')).toBeNull();
        expect($('decision-title')?.textContent).toBe('Symphonie des Aurès');
        const reference = $('decision-reference');
        expect(reference?.tagName).toBe('BDI');
        expect(reference?.getAttribute('dir')).toBe('ltr');
        expect(reference?.textContent).toBe(UUID);
        expect(dialog()?.textContent).toContain(
            fr['oeuvres.status.under_review'],
        );
        expect(dialog()?.textContent).toContain('Lina Haddad');
        expect(dialog()?.textContent).toContain('Musique');

        const link = $<HTMLAnchorElement>('decision-open-oeuvre');
        expect(link?.getAttribute('href')).toBe(`/admin/oeuvres/${UUID}`);
        expect(link?.getAttribute('target')).toBe('_blank');
        expect(link?.getAttribute('rel')).toBe('noopener noreferrer');
        expect(link?.textContent).toContain("Ouvrir l'oeuvre");

        expect($('check-required')?.dataset.ok).toBe('true');
        expect($('check-ready')?.dataset.ok).toBe('true');
        expect($('check-conditional')?.textContent).toContain(
            'Contrat de cession',
        );
        expect($('decision-holder')?.textContent).toContain(
            "En cours d'examen par vous",
        );

        // The footer is exactly the server's `allowed`.
        expect($('decision-approve')).not.toBeNull();
        expect($('decision-reject')).not.toBeNull();
        expect($('decision-open-review')).toBeNull();
        expect($('decision-take-over')).toBeNull();
    });

    it('shows a fetch error inline, and "Réessayer" fetches again', async () => {
        const { calls } = server({
            [DECISION]: [
                { status: 500, body: {} },
                { status: 200, body: summary() },
            ],
        });

        open();
        await settle();

        expect($('decision-load-error')?.textContent).toContain(
            fr['review.dialog.loadFailed'],
        );
        expect($('decision-retry')?.textContent).toContain('Réessayer');

        $('decision-retry')!.click();
        await settle();

        expect(calls).toHaveLength(2);
        expect($('decision-load-error')).toBeNull();
        expect($('decision-approve')).not.toBeNull();
    });

    it('offers nothing for a decided deposit and says who decided', async () => {
        server({
            [DECISION]: [
                {
                    status: 200,
                    body: summary({
                        status: 'registered',
                        holder: null,
                        heldByMe: false,
                        allowed: [],
                        lastDecision: {
                            to: 'registered',
                            by: 'Karim',
                            at: '2026-10-12T09:00:00Z',
                        },
                    }),
                },
            ],
        });

        open();
        await settle();

        expect($('decision-last')?.textContent).toContain('Karim');

        for (const action of [
            'open-review',
            'take-over',
            'approve',
            'reject',
        ]) {
            expect($(`decision-${action}`)).toBeNull();
        }
    });
});

describe('ReviewDecisionDialog — decisions', () => {
    it('"Commencer l\'examen" keeps the dialog open and shows Accept and Reject after the refetch', async () => {
        const { calls } = server({
            [DECISION]: [
                {
                    status: 200,
                    body: summary({
                        status: 'submitted',
                        holder: null,
                        heldByMe: false,
                        allowed: ['open_review'],
                    }),
                },
                { status: 200, body: summary() },
            ],
            [`POST /admin/oeuvres/${UUID}/review`]: [
                {
                    status: 200,
                    body: { status: 'under_review', replayed: false },
                },
            ],
        });

        open();
        await settle();

        expect($('decision-approve')).toBeNull();
        expect($('decision-open-review')?.textContent).toContain(
            "Commencer l'examen",
        );

        $('decision-open-review')!.click();
        await settle();

        expect(calls.map((call) => call.key)).toEqual([
            DECISION,
            `POST /admin/oeuvres/${UUID}/review`,
            DECISION,
        ]);
        expect(closeRequested()).toBe(false);
        expect(dialog()).not.toBeNull();
        expect(toast.success).toHaveBeenCalledWith(fr['review.toast.started']);
        expect(wrapper?.emitted('changed')).toHaveLength(1);
        expect($('decision-open-review')).toBeNull();
        expect($('decision-approve')).not.toBeNull();
        expect($('decision-reject')).not.toBeNull();
    });

    it('"Reprendre l\'examen" asks inline first, then posts the take-over', async () => {
        const { calls } = server({
            [DECISION]: [
                {
                    status: 200,
                    body: summary({
                        holder: { id: 'k', name: 'Karim' },
                        heldByMe: false,
                        allowed: ['take_over'],
                    }),
                },
                { status: 200, body: summary() },
            ],
            [`POST /admin/oeuvres/${UUID}/review`]: [
                {
                    status: 200,
                    body: { status: 'under_review', replayed: false },
                },
            ],
        });

        open();
        await settle();

        expect($('decision-holder')?.textContent).toContain(
            "En cours d'examen par Karim",
        );
        expect($('decision-approve')).toBeNull();

        $('decision-take-over')!.click();
        await settle();

        expect(calls).toHaveLength(1);
        expect($('take-over-confirm')?.textContent).toContain(
            'Karim examine ce dépôt. Le reprendre ?',
        );

        $('take-over-yes')!.click();
        await settle();

        expect(calls[1]).toEqual({
            key: `POST /admin/oeuvres/${UUID}/review`,
            body: { take_over: true },
        });
        expect(closeRequested()).toBe(false);
        expect($('take-over-confirm')).toBeNull();
        expect($('decision-approve')).not.toBeNull();
    });

    it('"Rejeter" switches to the reject screen; "Retour" keeps the text', async () => {
        server({ [DECISION]: [{ status: 200, body: summary() }] });

        open();
        await settle();

        $('decision-reject')!.click();
        await settle();

        expect($('review-screen')).toBeNull();
        const field = $<HTMLTextAreaElement>('reject-reason')!;
        type(field, VALID_REASON);
        await settle();

        $('reject-back')!.click();
        await settle();

        expect($('review-screen')).not.toBeNull();
        expect($('reject-reason')).toBeNull();

        $('decision-reject')!.click();
        await settle();

        expect($<HTMLTextAreaElement>('reject-reason')!.value).toBe(
            VALID_REASON,
        );
        expect($<HTMLButtonElement>('reject-confirm')!.disabled).toBe(false);
    });

    it('keeps "Confirmer le rejet" disabled under 20 characters, counted trimmed', async () => {
        const { calls } = server({
            [DECISION]: [{ status: 200, body: summary() }],
        });

        open();
        await settle();
        $('decision-reject')!.click();
        await settle();

        const field = $<HTMLTextAreaElement>('reject-reason')!;
        const confirm = $<HTMLButtonElement>('reject-confirm')!;

        expect(confirm.disabled).toBe(true);
        expect(confirm.textContent).toContain('Confirmer le rejet');

        type(field, `   ${'a'.repeat(19)}   `);
        await settle();
        expect(confirm.disabled).toBe(true);
        expect($('reject-counter')?.textContent).toContain('19 / 2000');

        // Enter in the form does not send it either.
        dialog()!
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { bubbles: true, cancelable: true }),
            );
        await settle();
        expect(calls).toHaveLength(1);

        type(field, 'a'.repeat(20));
        await settle();
        expect(confirm.disabled).toBe(false);
    });

    it('a chip inserts its text at the cursor', async () => {
        server({ [DECISION]: [{ status: 200, body: summary() }] });

        open();
        await settle();
        $('decision-reject')!.click();
        await settle();

        const field = $<HTMLTextAreaElement>('reject-reason')!;
        type(field, 'Bonjour. Merci.');
        await settle();
        field.setSelectionRange(8, 8);
        document
            .querySelector<HTMLButtonElement>('[data-chip="wrongFormat"]')!
            .click();
        await settle();

        expect(field.value).toBe('Bonjour. Format non conforme Merci.');
    });

    it('while busy: spinner on the clicked button, the others disabled, Escape ignored', async () => {
        const reply = deferred();
        server({
            [DECISION]: [{ status: 200, body: summary() }],
            [`POST /admin/oeuvres/${UUID}/approve`]: [reply.promise],
        });

        open();
        await settle();

        $('decision-approve')!.click();
        await settle();

        expect(dialog()?.getAttribute('aria-busy')).toBe('true');
        expect(
            $('decision-approve')?.querySelector(
                '[data-slot="spinner"], .animate-spin, svg[role="status"]',
            ),
        ).not.toBeNull();
        expect($<HTMLButtonElement>('decision-reject')!.disabled).toBe(true);
        expect($<HTMLButtonElement>('decision-approve')!.disabled).toBe(true);

        pressEscape();
        await settle();

        expect(closeRequested()).toBe(false);
        expect(dialog()).not.toBeNull();

        reply.release({
            status: 200,
            body: { status: 'registered', replayed: false },
        });
        await settle();
    });

    it('a success toasts, closes and asks the page to reload', async () => {
        server({
            [DECISION]: [{ status: 200, body: summary() }],
            [`POST /admin/oeuvres/${UUID}/approve`]: [
                {
                    status: 200,
                    body: { status: 'registered', replayed: false },
                },
            ],
        });

        open();
        await settle();
        $('decision-approve')!.click();
        await settle();

        expect(toast.success).toHaveBeenCalledWith(fr['review.toast.accepted']);
        expect(closeRequested()).toBe(true);
        expect(wrapper?.emitted('changed')).toHaveLength(1);
    });

    it('a rejection posts the trimmed reason, then closes', async () => {
        const { calls } = server({
            [DECISION]: [{ status: 200, body: summary() }],
            [`POST /admin/oeuvres/${UUID}/reject`]: [
                { status: 200, body: { status: 'rejected', replayed: false } },
            ],
        });

        open();
        await settle();
        $('decision-reject')!.click();
        await settle();
        type($<HTMLTextAreaElement>('reject-reason')!, `  ${VALID_REASON}  `);
        await settle();
        $('reject-confirm')!.click();
        await settle();

        expect(calls[1]).toEqual({
            key: `POST /admin/oeuvres/${UUID}/reject`,
            body: { reason: VALID_REASON },
        });
        expect(toast.success).toHaveBeenCalledWith(fr['review.toast.rejected']);
        expect(closeRequested()).toBe(true);
    });

    it('a greyed Accept says why, and is never posted', async () => {
        const { calls } = server({
            [DECISION]: [
                {
                    status: 200,
                    body: summary({
                        checklist: {
                            noFiles: false,
                            requiredMissing: ["Justificatif d'exploitation"],
                            filesNotReady: [
                                { name: 'livret.pdf', status: 'processing' },
                            ],
                            conditionalMissing: [],
                        },
                        allowed: ['reject'],
                        blocked: ['approve'],
                    }),
                },
            ],
        });

        open();
        await settle();

        const accept = $<HTMLButtonElement>('decision-approve')!;
        expect(accept.disabled).toBe(true);
        expect(accept.getAttribute('aria-describedby')).toBe(
            'approve-blocked-reason',
        );
        expect($('approve-blocked-reason')?.textContent).toContain(
            '1 fichier en cours de traitement',
        );
        expect($('approve-blocked-reason')?.textContent).toContain(
            '1 document obligatoire manquant',
        );
        expect($('check-required')?.textContent).toContain(
            "Justificatif d'exploitation",
        );
        expect($('check-ready')?.textContent).toContain('livret.pdf');
        expect($('check-ready')?.textContent).toContain(
            fr['media.status.processing'],
        );

        accept.click();
        await settle();
        expect(calls).toHaveLength(1);
    });
});

describe('ReviewDecisionDialog — refusals', () => {
    it('409 held: refetches and shows the holder and the take-over', async () => {
        server({
            [DECISION]: [
                { status: 200, body: summary() },
                {
                    status: 200,
                    body: summary({
                        holder: { id: 'k', name: 'Karim' },
                        heldByMe: false,
                        allowed: ['take_over'],
                    }),
                },
            ],
            [`POST /admin/oeuvres/${UUID}/approve`]: [
                {
                    status: 409,
                    body: {
                        conflict: {
                            kind: 'held',
                            by: 'Karim',
                            at: null,
                            status: 'under_review',
                        },
                    },
                },
            ],
        });

        open();
        await settle();
        $('decision-approve')!.click();
        await settle();

        expect($('decision-notice')?.textContent).toContain(
            "En cours d'examen par Karim",
        );
        expect(closeRequested()).toBe(false);
        expect($('decision-take-over')).not.toBeNull();
        expect($('decision-approve')).toBeNull();
        expect($('decision-holder')?.textContent).toContain('Karim');
    });

    it('409 decided: "Déjà traité par {admin} le {date}", then a refetch and a row refresh', async () => {
        const { calls } = server({
            [DECISION]: [
                { status: 200, body: summary() },
                {
                    status: 200,
                    body: summary({
                        status: 'registered',
                        holder: null,
                        heldByMe: false,
                        allowed: [],
                    }),
                },
            ],
            [`POST /admin/oeuvres/${UUID}/approve`]: [
                {
                    status: 409,
                    body: {
                        conflict: {
                            kind: 'decided',
                            by: 'Amina',
                            at: '2026-10-12T09:00:00Z',
                            status: 'registered',
                        },
                    },
                },
            ],
        });

        open();
        await settle();
        $('decision-approve')!.click();
        await settle();

        expect($('decision-notice')?.textContent).toContain(
            'Déjà traité par Amina le 12 oct. 2026',
        );
        expect(calls.at(-1)?.key).toBe(DECISION);
        expect(wrapper?.emitted('changed')).toHaveLength(1);
        expect($('decision-approve')).toBeNull();
        expect($('decision-reject')).toBeNull();
    });

    it('422 refused: the server problem list, by name — never a generic error', async () => {
        server({
            [DECISION]: [{ status: 200, body: summary() }],
            [`POST /admin/oeuvres/${UUID}/approve`]: [
                {
                    status: 422,
                    body: {
                        message: 'refused',
                        problems: [
                            {
                                code: 'file_quarantined',
                                params: { name: 'pochette.png' },
                                message: '',
                            },
                            {
                                code: 'slot_empty',
                                params: { name: 'Contrat' },
                                message: '',
                            },
                        ],
                    },
                },
            ],
        });

        open();
        await settle();
        $('decision-approve')!.click();
        await settle();

        const list = $('decision-problems')!;
        expect(list.textContent).toContain('pochette.png');
        expect(list.textContent).toContain(
            'Document obligatoire manquant : Contrat.',
        );
        expect(list.textContent).not.toContain(fr['review.toast.failed']);
        expect(closeRequested()).toBe(false);
    });

    it('422 on the reason: shown on the field, on the reject screen', async () => {
        server({
            [DECISION]: [{ status: 200, body: summary() }],
            [`POST /admin/oeuvres/${UUID}/reject`]: [
                {
                    status: 422,
                    body: { errors: { reason: ['Le motif est trop court.'] } },
                },
            ],
        });

        open();
        await settle();
        $('decision-reject')!.click();
        await settle();
        type($<HTMLTextAreaElement>('reject-reason')!, VALID_REASON);
        await settle();
        $('reject-confirm')!.click();
        await settle();

        expect($('reject-error')?.textContent).toContain(
            'Le motif est trop court.',
        );
        expect($<HTMLTextAreaElement>('reject-reason')!.value).toBe(
            VALID_REASON,
        );
    });

    it('a network failure says so in the dialog and changes nothing', async () => {
        const { mock } = server({
            [DECISION]: [{ status: 200, body: summary() }],
        });

        open();
        await settle();
        mock.mockImplementationOnce(async () => {
            throw new TypeError('offline');
        });
        $('decision-approve')!.click();
        await settle();

        expect($('decision-notice')?.textContent).toContain(
            fr['review.toast.failed'],
        );
        expect(wrapper?.emitted('changed')).toBeUndefined();
        expect($('decision-approve')).not.toBeNull();
    });
});

describe('ReviewDecisionDialog — locales', () => {
    it('renders in Arabic, right to left', async () => {
        server({
            [DECISION]: [
                {
                    status: 200,
                    body: summary({
                        holder: { id: 'k', name: 'Karim' },
                        heldByMe: false,
                        allowed: ['take_over'],
                    }),
                },
            ],
        });

        open('ar');
        await settle();

        expect($('decision-take-over')?.textContent).toContain(
            ar['review.dialog.takeOver'],
        );
        expect(dialog()?.textContent).toContain(ar['review.dialog.openOeuvre']);
        // Latin reference stays isolated left-to-right inside RTL text.
        expect($('decision-reference')?.getAttribute('dir')).toBe('ltr');
    });

    it('renders in English', async () => {
        server({ [DECISION]: [{ status: 200, body: summary() }] });

        open('en');
        await settle();

        expect($('decision-approve')?.textContent).toContain(
            en['review.accept.confirm'],
        );
        expect(dialog()?.textContent).toContain('Open the work');
    });
});
