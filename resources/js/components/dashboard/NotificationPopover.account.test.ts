// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';

/** Account notifications (account_created, account_login) in the bell. */
const inertia = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: inertia.visit, on: () => () => undefined },
}));
vi.mock('@/routes/notifications', () => ({
    index: () => ({ url: '/notifications' }),
    read: (id: string) => ({ url: `/notifications/${id}/read` }),
    readAll: () => ({ url: '/notifications/read-all' }),
}));

const { default: NotificationPopover } =
    await import('./NotificationPopover.vue');

const OCCURRED = '2026-11-19T22:30:00Z';
const SECURITY = 'http://onda-storage.test/settings/security';

function makeRow(
    key: string,
    extra: Record<string, unknown> = {},
    url: string | null = SECURITY,
    createdAt: string | null = OCCURRED,
) {
    return {
        id: 'n1',
        data: {
            type: key.includes('created') ? 'account_created' : 'account_login',
            i18n_key: key,
            params: [],
            occurred_at: OCCURRED,
            url,
            ...extra,
        },
        read_at: null,
        created_at: createdAt,
    };
}

let wrapper: VueWrapper | null = null;

const settle = async (): Promise<void> => {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 30));
    await nextTick();
};

beforeEach(() => inertia.visit.mockClear());

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

async function openBell(
    locale: string,
    row: ReturnType<typeof makeRow>,
): Promise<HTMLElement> {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ unread_count: 1, notifications: [row] }),
        })),
    );

    wrapper = mount(NotificationPopover, {
        global: {
            plugins: [
                createI18n({
                    legacy: false,
                    locale,
                    fallbackLocale: 'en',
                    messages: { en, fr, ar },
                    missingWarn: false,
                    fallbackWarn: false,
                }),
            ],
        },
        attachTo: document.body,
    });
    await settle();

    const bell = document.querySelector<HTMLElement>(
        '[data-testid="notification-bell"]',
    )!;
    bell.focus();
    bell.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
    );
    await settle();

    return document.querySelector<HTMLElement>(
        '[data-testid="notification-item"]',
    )!;
}

const KEYS = [
    'created',
    'login_password',
    'login_two_factor',
    'login_recovery_code',
    'login_passkey',
] as const;

const EXPECTED: Record<string, Record<(typeof KEYS)[number], string>> = {
    fr: {
        created: 'Bienvenue sur ONDA : votre compte a été créé le',
        login_password: 'Connexion avec mot de passe le',
        login_two_factor: "Connexion avec code d'authentification (2FA) le",
        login_recovery_code: 'Connexion avec code de récupération le',
        login_passkey: "Connexion avec clé d'accès (passkey) le",
    },
    en: {
        created: 'Welcome to ONDA: your account was created on',
        login_password: 'Signed in with your password on',
        login_two_factor: 'Signed in with an authentication code (2FA) on',
        login_recovery_code: 'Signed in with a recovery code on',
        login_passkey: 'Signed in with a passkey on',
    },
    ar: {
        created: 'مرحبًا بك في ONDA: تم إنشاء حسابك في',
        login_password: 'تم تسجيل الدخول بكلمة المرور في',
        login_two_factor: 'تم تسجيل الدخول برمز المصادقة الثنائية في',
        login_recovery_code: 'تم تسجيل الدخول برمز الاسترداد في',
        login_passkey: 'تم تسجيل الدخول بمفتاح المرور في',
    },
};

describe('NotificationPopover — account notifications', () => {
    for (const locale of ['fr', 'en', 'ar']) {
        for (const kind of KEYS) {
            it(`renders ${kind} in ${locale} with the Algiers time`, async () => {
                const item = await openBell(
                    locale,
                    makeRow(`notifications.account.${kind}`),
                );
                const text = item.textContent ?? '';

                expect(text).toContain(EXPECTED[locale][kind]);
                // 22:30 UTC is 23:30 in Africa/Algiers, Latin digits.
                expect(text).toMatch(/(23[:.]30|11:30)/);
                expect(text).not.toContain('{time}');
            });
        }
    }

    it('uses a dedicated title per type', async () => {
        const item = await openBell(
            'fr',
            makeRow('notifications.account.created', {}, null),
        );

        expect(item.textContent).toContain('Bienvenue sur ONDA');
        wrapper?.unmount();
        document.body.innerHTML = '';

        const login = await openBell(
            'en',
            makeRow('notifications.account.login_password'),
        );

        expect(login.textContent).toContain('New sign-in');
    });

    it('does not throw or show Invalid when occurred_at is bad', async () => {
        const item = await openBell(
            'fr',
            makeRow(
                'notifications.account.login_password',
                { occurred_at: 'garbage' },
                SECURITY,
                null,
            ),
        );

        expect(item.textContent).toContain('Connexion avec mot de passe.');
        expect(item.textContent).not.toContain('Invalid');
    });

    it('falls back to created_at when occurred_at is absent', async () => {
        const item = await openBell(
            'en',
            makeRow('notifications.account.login_passkey', {
                occurred_at: undefined,
            }),
        );

        expect(item.textContent).toMatch(/(23[:.]30|11:30)/);
        expect(item.textContent).not.toContain('Invalid');
    });

    it('falls back to the generic message for an unknown account key', async () => {
        const item = await openBell(
            'en',
            makeRow('notifications.account.unknown_thing'),
        );

        expect(item.textContent).toContain('New notification');
    });

    it('visits the security url for a login row', async () => {
        const item = await openBell(
            'fr',
            makeRow('notifications.account.login_password'),
        );

        item.click();
        await settle();

        expect(inertia.visit).toHaveBeenCalledWith('/settings/security');
    });

    it('does not navigate for account_created (null url)', async () => {
        const item = await openBell(
            'fr',
            makeRow('notifications.account.created', {}, null),
        );

        item.click();
        await settle();

        expect(inertia.visit).not.toHaveBeenCalled();
    });

    const TITLES: Record<string, [string, string]> = {
        fr: ['Bienvenue sur ONDA', 'Nouvelle connexion'],
        en: ['Welcome to ONDA', 'New sign-in'],
        ar: ['مرحبًا بك في ONDA', 'تسجيل دخول جديد'],
    };

    for (const locale of ['fr', 'en', 'ar']) {
        it(`titles come from notifications.title.* in ${locale}`, async () => {
            const bundle = { fr, en, ar }[locale] as Record<string, string>;

            expect(bundle['notifications.title.account_created']).toBe(
                TITLES[locale][0],
            );
            expect(bundle['notifications.title.account_login']).toBe(
                TITLES[locale][1],
            );
            expect(bundle['notifications.account.createdTitle']).toBe(
                undefined,
            );
            expect(bundle['notifications.account.loginTitle']).toBe(undefined);

            const created = await openBell(
                locale,
                makeRow('notifications.account.created', {}, null),
            );

            expect(created.querySelector('bdi')?.textContent).toBe(
                TITLES[locale][0],
            );
            wrapper?.unmount();
            document.body.innerHTML = '';

            const login = await openBell(
                locale,
                makeRow('notifications.account.login_passkey'),
            );

            expect(login.querySelector('bdi')?.textContent).toBe(
                TITLES[locale][1],
            );
        });
    }

    it('falls back to the generic title for an unknown type', async () => {
        const item = await openBell(
            'en',
            makeRow('notifications.account.login_password', {
                type: 'something_else',
            }),
        );

        expect(item.querySelector('bdi')?.textContent).toBe('New notification');
    });

    it('renders UserPlus for created and LogIn for every login_ kind', async () => {
        const created = await openBell(
            'en',
            makeRow('notifications.account.created', {}, null),
        );

        expect(created.querySelector('svg.lucide-user-plus')).not.toBeNull();
        wrapper?.unmount();
        document.body.innerHTML = '';

        for (const kind of KEYS.filter((k) => k !== 'created')) {
            const item = await openBell(
                'en',
                makeRow(`notifications.account.${kind}`),
            );

            expect(item.querySelector('svg.lucide-log-in')).not.toBeNull();
            wrapper?.unmount();
            document.body.innerHTML = '';
        }
    });

    it('uses the _notime text when there is neither occurred_at nor created_at', async () => {
        const item = await openBell(
            'en',
            makeRow(
                'notifications.account.login_password',
                { occurred_at: undefined },
                SECURITY,
                null,
            ),
        );

        expect(item.textContent).toContain(
            "Signed in with your password. If this wasn't you, secure your account.",
        );
        expect(item.textContent).not.toContain('{time}');
    });

    it('falls back to created_at when occurred_at is missing (en date)', async () => {
        const item = await openBell(
            'en',
            makeRow('notifications.account.login_password', {
                occurred_at: undefined,
            }),
        );

        expect(item.textContent).toMatch(/Nov 19, 2026/);
        expect(item.textContent).toMatch(/(23[:.]30|11:30)/);
    });

    it('wraps {time} in LRI/PDI isolates in the ar bundle only', () => {
        const pick = (m: unknown) => m as Record<string, string>;
        const count = (s: string, sub: string) => s.split(sub).length - 1;

        for (const kind of KEYS) {
            const key = `notifications.account.${kind}`;
            const value = pick(ar)[key];

            expect(count(value, '\u2066{time}\u2069')).toBe(1);

            const notime = pick(ar)[`${key}_notime`];

            expect(notime).toBeDefined();
            expect(notime).not.toContain('\u2066');
            expect(notime).not.toContain('\u2069');

            for (const bundle of [fr, en]) {
                expect(pick(bundle)[key]).not.toContain('\u2066');
                expect(pick(bundle)[key]).not.toContain('\u2069');
            }
        }
    });

    it('renders the ar time isolated by LRI/PDI', async () => {
        const item = await openBell(
            'ar',
            makeRow('notifications.account.login_password'),
        );
        const text = item.textContent ?? '';

        expect(text).toMatch(
            /\u2066[^\u2069]*(23[:.]30|11:30)[^\u2069]*\u2069/,
        );
        expect(text).not.toContain('{time}');
    });

    it('has identical key sets across the ar, fr and en bundles', () => {
        const keys = (m: Record<string, unknown>) => Object.keys(m).sort();

        expect(keys(fr)).toEqual(keys(en));
        expect(keys(ar)).toEqual(keys(en));
    });

    it('keeps notifications.account.* keys identical across locales', () => {
        const pick = (m: Record<string, string>) =>
            Object.keys(m)
                .filter((k) => k.startsWith('notifications.account.'))
                .sort();

        expect(pick(fr)).toEqual(pick(en));
        expect(pick(ar)).toEqual(pick(en));
        expect(pick(en)).toHaveLength(10);
    });
});
