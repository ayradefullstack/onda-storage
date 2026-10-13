// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { shouldTrackVisit } from '@/composables/useGlobalLoader';
import en from '@/locales/en.json';
import type { ConsultationDescriptor } from '@/types/consultation';
import FileReviewLayout from './FileReviewLayout.vue';
import { POLL_INTERVAL_MS } from './useConsultationPolling';

const { router } = vi.hoisted(() => ({
    router: {
        visit: vi.fn(),
        reload: vi.fn(),
        on: vi.fn(),
        cancelAll: vi.fn(),
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router,
}));

const pending: ConsultationDescriptor = {
    family: 'text',
    mode: 'derivative',
    original: null,
    status: 'pending',
    reason: null,
    notice: null,
    assets: [],
    pageCount: null,
    watermark: null,
    meta: {
        filename: 'lettre.txt',
        sizeBytes: 2048,
        mime: 'text/plain',
        sha256: 'ab'.repeat(32),
        depositedAt: '2026-10-01T10:00:00Z',
        slotLabel: null,
    },
    actions: { download: false },
};

beforeEach(() => vi.useFakeTimers());

afterEach(() => {
    vi.useRealTimers();
    router.reload.mockClear();
    document.body.innerHTML = '';
});

describe('the consultation poll on the review page', () => {
    it('reloads as a silent partial visit, which never raises the global loader', async () => {
        const wrapper = mount(FileReviewLayout, {
            props: {
                oeuvre: { uuid: 'o1', title: 'Mon oeuvre', created_at: '' },
                file: {
                    uuid: 'f1',
                    original_name: 'lettre.txt',
                    extension: 'txt',
                    status: 'ready',
                },
                siblings: [
                    { uuid: 'f1', original_name: 'lettre.txt', slot: null },
                ],
                consultation: pending,
                backHref: '/back',
                fileHref: (uuid: string) => `/file/${uuid}`,
            },
            global: {
                plugins: [
                    createI18n({
                        legacy: false,
                        locale: 'en',
                        messages: { en },
                        missingWarn: false,
                        fallbackWarn: false,
                    }),
                ],
            },
        });

        expect(router.reload).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(POLL_INTERVAL_MS * 3 + 50);

        expect(router.reload).toHaveBeenCalledTimes(3);

        for (const [options] of router.reload.mock.calls) {
            expect(options.only).toEqual(['consultation']);
            expect(options.meta).toEqual({ silent: true });
            expect(shouldTrackVisit(options)).toBe(false);
        }

        wrapper.unmount();
    });
});
