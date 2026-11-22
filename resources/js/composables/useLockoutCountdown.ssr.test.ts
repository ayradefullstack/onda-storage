import { renderToString } from '@vue/server-renderer';
import { describe, expect, it, vi } from 'vitest';
import { computed, createSSRApp, defineComponent, h, ref } from 'vue';
import { createI18n } from 'vue-i18n';
import AuthLockoutNotice from '@/components/auth/AuthLockoutNotice.vue';
import { pluralRules } from '@/lib/i18nPlural';
import en from '@/locales/en.json';
import { useLockoutCountdown } from './useLockoutCountdown';

/**
 * Runs in the plain NODE environment on purpose (no `@vitest-environment`
 * directive): there is no window and no document, exactly as during server
 * rendering. The composable must not touch either while setting up.
 */
vi.mock('@/components/PasskeyVerify.vue', () => ({
    default: { render: () => null },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...((await importOriginal()) as Record<string, unknown>),
    Head: { render: () => null },
}));

function makeI18n() {
    return createI18n({
        legacy: false,
        locale: 'en',
        messages: { en },
        pluralRules,
        missingWarn: false,
        fallbackWarn: false,
    });
}

describe('useLockoutCountdown on the server (node, no DOM)', () => {
    it('runs in an environment without window or document', () => {
        expect(typeof window).toBe('undefined');
        expect(typeof document).toBe('undefined');
    });

    it('sets up inside a server-rendered component without throwing and renders no notice', async () => {
        const Host = defineComponent({
            setup() {
                const email = ref('author@example.com');
                const lockout = useLockoutCountdown({
                    email: () => email.value,
                });
                const description = computed(() => 'locked');

                return () =>
                    h(AuthLockoutNotice, {
                        active: lockout.active.value,
                        ready: lockout.ready.value,
                        clockText: lockout.clockText.value,
                        description: description.value,
                        soundSupported: lockout.soundSupported,
                        soundEnabled: lockout.soundEnabled.value,
                        soundBlocked: lockout.soundBlocked.value,
                    });
            },
        });
        const app = createSSRApp(Host);
        app.use(makeI18n());

        const html = await renderToString(app);

        expect(html).not.toContain('lockout-notice');
        expect(html).not.toContain('lockout-timer');
    });

    it('needs no listener API: no window or document global is ever read or created', async () => {
        let disposeOk = false;
        const Host = defineComponent({
            setup() {
                const lockout = useLockoutCountdown({ email: () => 'a@b.c' });
                // Record is client-only in practice; dispose must also be safe.
                lockout.dispose();
                disposeOk = true;

                return () => h('div');
            },
        });

        await renderToString(createSSRApp(Host));

        expect(disposeOk).toBe(true);
        expect(typeof window).toBe('undefined');
        expect(typeof document).toBe('undefined');
    });

    it('server-renders the REAL Login page without throwing and without a lockout notice', async () => {
        const { default: Login } = await import('@/pages/auth/Login.vue');
        const app = createSSRApp({
            render: () =>
                h(Login as never, {
                    status: null,
                    canResetPassword: true,
                }),
        });
        app.use(makeI18n());

        const html = await renderToString(app);

        expect(html).toContain('<form');
        expect(html).not.toContain('lockout-notice');
        // The first import of the whole Login page graph can exceed the default
        // 5 s while the other test files load in parallel; not a logic limit.
    }, 30_000);
});
