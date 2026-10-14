// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import type { Component } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';

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

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
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
