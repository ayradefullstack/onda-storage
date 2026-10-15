import { renderToString } from '@vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import { createSSRApp, h } from 'vue';
import type { Component } from 'vue';
import { createI18n } from 'vue-i18n';
import type {
    DashboardAction,
    DashboardDecision,
    DashboardQuota,
    DashboardRecent,
} from '@/components/dashboard/types';
import { formatBytes } from '@/lib/format';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';

const auth = { user: { name: 'Amina Haddad' } as { name: string } | null };

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: {
        props: ['href'],
        setup:
            (
                props: { href: { url: string } | string },
                {
                    slots,
                    attrs,
                }: {
                    slots: { default?: () => unknown };
                    attrs: Record<string, unknown>;
                },
            ) =>
            () =>
                h(
                    'a',
                    {
                        ...attrs,
                        href:
                            typeof props.href === 'string'
                                ? props.href
                                : props.href.url,
                    },
                    slots.default?.() as never,
                ),
    },
    usePage: () => ({ props: { auth } }),
}));

vi.mock('@/routes/author', () => ({
    dashboard: () => ({ url: '/author/dashboard' }),
}));
vi.mock('@/routes/oeuvres', () => ({
    index: (options?: { query?: Record<string, string> }) => ({
        url: options?.query
            ? `/author/oeuvres?${new URLSearchParams(options.query)}`
            : '/author/oeuvres',
    }),
    create: () => ({ url: '/author/oeuvres/create' }),
    show: (uuid: string) => ({ url: `/author/oeuvres/${uuid}` }),
}));

const { default: Dashboard } = await import('./Dashboard.vue');

const ZERO = {
    draft: 0,
    submitted: 0,
    under_review: 0,
    registered: 0,
    rejected: 0,
    all: 0,
    pending: 0,
};

const label = (uuid: string, title: string | null = null) => ({
    uuid,
    title,
    college_name: 'Musique',
    created_at: '2026-10-01T10:00:00+00:00',
});

const rejected: DashboardAction = {
    uuid: '01a1-rejected',
    label_source: label('01a1-rejected', 'Rai d’Oran'),
    status: 'rejected',
    kind: 'rejected',
    blockers: [],
    rejection: {
        reason: 'Le contrat de cession signé est manquant.',
        at: '2026-10-12T09:00:00+00:00',
    },
};

const incomplete: DashboardAction = {
    uuid: '01a1-incomplete',
    label_source: label('01a1-incomplete', 'Nuits du Hoggar'),
    status: 'draft',
    kind: 'incomplete',
    blockers: [
        { code: 'slot_empty', params: { name: 'Partition' } },
        {
            code: 'file_in_pipeline',
            params: { name: 'master.wav', status: 'processing' },
        },
    ],
    rejection: null,
};

const ready: DashboardAction = {
    uuid: '01a1-ready',
    label_source: label('01a1-ready', 'Prêt'),
    status: 'draft',
    kind: 'ready',
    blockers: [],
    rejection: null,
};

const recent: DashboardRecent[] = [
    {
        uuid: '01a13416-d72e-71d6-8743-e20453b58eaa',
        label_source: label('01a13416-d72e-71d6-8743-e20453b58eaa', null),
        status: 'under_review',
        last_activity_at: '2026-10-14T08:00:00+00:00',
    },
];

const decisions: DashboardDecision[] = [
    {
        uuid: 'rev-1',
        oeuvre_uuid: '01a1-registered',
        label_source: label('01a1-registered', 'Enregistrée A'),
        to_status: 'registered',
        at: '2026-10-13T08:00:00+00:00',
    },
];

const quota: DashboardQuota = {
    used_bytes: 2 * 1024 ** 3,
    reserved_bytes: 512 * 1024 ** 2,
    limit_bytes: 50 * 1024 ** 3,
};

interface Props {
    counts: Record<string, number>;
    actions: { items: DashboardAction[]; total: number };
    recent: DashboardRecent[];
    quota: DashboardQuota | null;
    decisions: DashboardDecision[];
}

const populated = (overrides: Partial<Props> = {}): Props => ({
    counts: {
        draft: 2,
        submitted: 1,
        under_review: 1,
        registered: 1,
        rejected: 1,
        all: 6,
        pending: 2,
    },
    actions: { items: [rejected, incomplete, ready], total: 3 },
    recent,
    quota,
    decisions,
    ...overrides,
});

async function render(props: Props, locale = 'fr'): Promise<string> {
    const i18n = createI18n({
        legacy: false,
        locale,
        fallbackLocale: 'en',
        messages: { en, fr, ar },
        missingWarn: false,
        fallbackWarn: false,
    });
    const app = createSSRApp({
        render: () => h(Dashboard as Component, props),
    });
    app.use(i18n);

    return renderToString(app);
}

/** Text as the SSR renderer escapes it. */
const esc = (text: string): string =>
    text.replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;');

/** The opening tag carrying a test id, whatever its attribute order. */
function tag(html: string, id: string): string {
    return (
        new RegExp(`<[a-z]+ [^>]*data-testid="${id}"[^>]*>`).exec(html)?.[0] ??
        ''
    );
}

function testid(html: string, id: string): string {
    const match = new RegExp(`data-testid="${id}"[^>]*>([\\s\\S]*?)</`).exec(
        html,
    );

    return match?.[1] ?? '';
}

describe('author/Dashboard', () => {
    it('a first-time author sees only the greeting and the three-step start', async () => {
        const html = await render({
            counts: ZERO,
            actions: { items: [], total: 0 },
            recent: [],
            quota: null,
            decisions: [],
        });

        expect(html).toContain('data-testid="dashboard-first-run"');
        expect(html).toContain(esc(fr['dashboard.firstRun.step1']));
        expect(html).toContain(esc(fr['dashboard.firstRun.step3']));
        expect(html).toContain('href="/author/oeuvres/create"');
        expect(html).toContain(fr['dashboard.firstRun.cta']);
        expect(html).toContain('Bienvenue, Amina Haddad');
        expect(html).not.toContain('data-testid="count-draft"');
        expect(html).not.toContain('data-testid="dashboard-recent"');
    });

    it('shows the four real counts, zeros as 0, each linked to its filter', async () => {
        const html = await render(
            populated({
                counts: { ...ZERO, draft: 3, all: 3 },
                actions: { items: [], total: 3 },
            }),
        );

        for (const [key, value] of [
            ['draft', '3'],
            ['pending', '0'],
            ['registered', '0'],
            ['rejected', '0'],
        ]) {
            expect(tag(html, `count-${key}`), key).toContain(
                `href="/author/oeuvres?status=${key}"`,
            );
            const card = new RegExp(
                `data-testid="count-${key}"[\\s\\S]*?data-slot="count-up">(\\d+)<`,
            ).exec(html);
            expect(card?.[1], key).toBe(value);
        }

        expect(html).toContain(fr['dashboard.counts.pending']);
    });

    it('"Nouveau dépôt" goes straight to the creation page', async () => {
        const html = await render(populated());

        expect(tag(html, 'new-deposit')).toContain(
            'href="/author/oeuvres/create"',
        );
        expect(html).toContain(fr['dashboard.newDepositBtn']);
    });

    it('lists the actions: the verbatim reason, the gate blockers, and "ready"', async () => {
        const html = await render(populated());

        expect(html).toContain('data-testid="action-rejected"');
        expect(html).toContain(
            esc('Le contrat de cession signé est manquant.'),
        );
        expect(html).toContain(esc(fr['dashboard.actions.fix']));
        expect(html).toContain(
            esc(fr['oeuvres.gate.slot_empty'].replace('{name}', 'Partition')),
        );
        expect(html).toContain(
            esc(
                fr['oeuvres.gate.file_in_pipeline']
                    .replace('{name}', 'master.wav')
                    .replace('{status}', fr['media.status.processing']),
            ),
        );
        expect(html).toContain(fr['dashboard.actions.ready']);
        expect(html).toContain('href="/author/oeuvres/01a1-ready"');
    });

    it('hides every block that has nothing real to show', async () => {
        const html = await render(
            populated({
                actions: { items: [], total: 0 },
                quota: null,
                decisions: [],
            }),
        );

        expect(html).not.toContain('data-testid="dashboard-actions"');
        expect(html).not.toContain('data-testid="dashboard-storage"');
        expect(html).not.toContain('data-testid="dashboard-decisions"');
        expect(html).toContain('data-testid="dashboard-recent"');
    });

    it('recent works link to the oeuvre and show no fabricated reference', async () => {
        const html = await render(populated(), 'ar');

        // A uuid prefix is a timestamp, not a reference: it is not shown.
        expect(html).not.toContain('data-testid="recent-reference"');
        expect(html).not.toContain('>01a13416<');
        expect(testid(html, 'recent-activity')).toContain(
            ar['dashboard.recent.activity'],
        );
        expect(html).toContain(
            'href="/author/oeuvres/01a13416-d72e-71d6-8743-e20453b58eaa"',
        );
        expect(html).toContain(ar['oeuvres.status.under_review']);
    });

    it('storage counts reservations, and warns above 80 %', async () => {
        const calm = await render(populated());

        expect(calm).toContain('data-warning="false"');
        // 2 GB used + 0.5 GB reserved.
        expect(calm).toContain(formatBytes(2.5 * 1024 ** 3, 'fr'));
        expect(calm).toContain(formatBytes(50 * 1024 ** 3, 'fr'));
        expect(calm).toContain('data-testid="storage-reserved"');
        expect(calm).not.toContain('data-testid="storage-warning"');

        const full = await render(
            populated({
                quota: {
                    used_bytes: 41 * 1024 ** 3,
                    reserved_bytes: 0,
                    limit_bytes: 50 * 1024 ** 3,
                },
            }),
        );

        expect(full).toContain('data-warning="true"');
        expect(full).toContain('data-testid="storage-warning"');
        expect(full).not.toContain('data-testid="storage-reserved"');
    });

    it('decisions name the ONDA outcome and link to the oeuvre', async () => {
        const html = await render(populated());

        expect(html).toContain('data-testid="decision-registered"');
        expect(html).toContain('href="/author/oeuvres/01a1-registered"');
        expect(html).toContain(esc(fr['dashboard.decisions.registered']));
    });

    it('renders no royalty, membership, IPI or certificate content', async () => {
        for (const locale of ['fr', 'ar', 'en']) {
            const html = await render(populated(), locale);

            expect(html).not.toMatch(
                /DZD|IPI|royalt|redevance|Attestation|certificat|شهادة|Mohamed Benali|DZ-2026/i,
            );
        }
    });

    it('labels resolve in Arabic, French and English', async () => {
        for (const [locale, messages] of [
            ['fr', fr],
            ['ar', ar],
            ['en', en],
        ] as const) {
            const html = await render(populated(), locale);

            for (const key of [
                'dashboard.counts.pending',
                'dashboard.actions.title',
                'dashboard.recent.title',
                'dashboard.storage.title',
                'dashboard.decisions.title',
            ] as const) {
                expect(html, `${locale} ${key}`).toContain(esc(messages[key]));
            }

            expect(testid(html, 'dashboard-greeting')).not.toBe('');
        }
    });
});
