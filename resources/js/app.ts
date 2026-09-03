import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeGlobalLoader } from '@/composables/useGlobalLoader';
import {
    applyLocale,
    createAppI18n,
    FALLBACK_LOCALE,
    SUPPORTED_LOCALES,
} from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Home':
                return PublicLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#1C9976',
    },
    withApp(app, { page }) {
        const locale = SUPPORTED_LOCALES.includes(page.props.locale)
            ? page.props.locale
            : FALLBACK_LOCALE;

        const i18n = createAppI18n(locale);
        app.use(i18n);

        // Inertia visits (e.g. the language switcher) never re-run
        // app.blade.php, so nothing would otherwise update vue-i18n's active
        // locale or <html dir/lang> after the first load — this keeps both
        // in sync with every navigation, with no page reload.
        router.on('navigate', (event) => {
            const nextLocale = event.detail.page.props.locale;

            if (SUPPORTED_LOCALES.includes(nextLocale)) {
                applyLocale(i18n, nextLocale);
            }
        });
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// Listen for global Inertia requests and show ONDA loading screen...
initializeGlobalLoader();

// This will listen for flash toast data from the server...
initializeFlashToast();
