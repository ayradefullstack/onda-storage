// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import ar from '@/locales/ar.json';
import en from '@/locales/en.json';
import fr from '@/locales/fr.json';

/**
 * The `file_rejected` notification (App\Notifications\MediaFileRejected) in
 * the author's bell: the deposit as title, the file name in the message,
 * the officer's reason as its own block, the link to the deposit — and no
 * officer name anywhere.
 */
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

const REASON = 'Le document est illisible, merci de le déposer à nouveau.';

const row = {
    id: 'n1',
    data: {
        type: 'file_rejected',
        oeuvre_uuid: '0199-abcd',
        reference: '0199-abcd',
        title: 'Symphonie des Aurès',
        label_source: {
            uuid: '0199-abcd',
            title: 'Symphonie des Aurès',
            college_name: 'Musique',
            created_at: '2026-10-01T08:00:00+00:00',
        },
        file_uuid: 'f1',
        file_name: 'contrat-signe.pdf',
        slot: { fr: 'Contrat', ar: 'العقد', en: 'Contract' },
        i18n_key: 'notifications.file.rejected',
        params: { file: 'contrat-signe.pdf' },
        reason: REASON,
        decided_at: '2026-10-18T08:00:00+00:00',
        url: 'http://onda-storage.test/author/oeuvres/0199-abcd',
    },
    read_at: null,
    created_at: new Date(Date.now() - 3600 * 1000).toISOString(),
};

let wrapper: VueWrapper | null = null;

const settle = async (): Promise<void> => {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 30));
    await nextTick();
};

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

async function openBell(locale: string): Promise<HTMLElement> {
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

describe('NotificationPopover — file_rejected', () => {
    it('renders the deposit, the file name, the reason and links to the deposit', async () => {
        const item = await openBell('fr');

        expect(item.dataset.type).toBe('file_rejected');
        expect(item.textContent).toContain('Symphonie des Aurès');
        expect(item.textContent).toContain(
            'Le fichier « contrat-signe.pdf » a été rejeté.',
        );
        expect(
            item.querySelector('[data-testid="notification-reason"]')!
                .textContent,
        ).toContain(REASON);

        item.click();
        await settle();

        // Opens the deposit scrolled to the file (the page reads the hash).
        expect(inertia.visit).toHaveBeenCalledWith(
            '/author/oeuvres/0199-abcd#file-f1',
        );
    });

    it('renders in Arabic', async () => {
        const item = await openBell('ar');

        expect(item.textContent).toContain(
            'تم رفض الملف « contrat-signe.pdf ».',
        );
    });
});
