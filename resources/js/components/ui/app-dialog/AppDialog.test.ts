// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, nextTick, ref } from 'vue';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import AppDialog from './AppDialog.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en },
    missingWarn: false,
    fallbackWarn: false,
});

/** A page with a button that opens a dialog holding far too much text. */
const Host = defineComponent({
    components: { AppDialog },
    props: { busy: { type: Boolean, default: false } },
    setup() {
        return { open: ref(false) };
    },
    template: `
        <button id="opener" type="button" @click="open = true">Open</button>
        <AppDialog v-model:open="open" title="Delete the work" description="This cannot be undone." :busy="busy">
            <p v-for="n in 200" :key="n" class="line">Line {{ n }}</p>
            <template #footer>
                <button id="cancel" type="button" @click="open = false">Cancel</button>
                <button id="confirm" type="button">Delete</button>
            </template>
        </AppDialog>
    `,
});

let wrapper: VueWrapper | null = null;

const settle = async (): Promise<void> => {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 30));
    await nextTick();
};

async function openDialog(busy = false): Promise<HTMLElement> {
    wrapper = mount(Host, {
        props: { busy },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

    const opener = document.querySelector<HTMLElement>('#opener')!;

    opener.focus();
    opener.click();
    await settle();

    return document.querySelector<HTMLElement>('[data-slot="app-dialog"]')!;
}

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

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
});

describe('AppDialog', () => {
    it('keeps the footer outside the scrolling body, however long the content', async () => {
        const dialog = await openDialog();

        const body = dialog.querySelector<HTMLElement>(
            '[data-slot="app-dialog-body"]',
        )!;
        const viewport = body.querySelector<HTMLElement>(
            '[data-slot="scroll-area-viewport"]',
        )!;
        const footer = dialog.querySelector<HTMLElement>(
            '[data-slot="app-dialog-footer"]',
        )!;
        const header = dialog.querySelector<HTMLElement>(
            '[data-slot="app-dialog-header"]',
        )!;

        // All 200 lines live inside the one scroll container ...
        expect(viewport.querySelectorAll('.line')).toHaveLength(200);
        expect(viewport.className).toContain('overflow-y-auto');
        expect(viewport.className).toContain('overscroll-contain');

        // ... and neither the header nor the actions are in it: they cannot
        // scroll away. The panel itself is capped to the viewport.
        expect(viewport.contains(footer)).toBe(false);
        expect(viewport.contains(header)).toBe(false);
        expect(footer.className).toContain('shrink-0');
        expect(header.className).toContain('shrink-0');
        expect(dialog.className).toContain('max-h-[min(90dvh,48rem)]');
        expect(dialog.className).toContain('overflow-hidden');
        expect(footer.querySelector('#confirm')).not.toBeNull();
    });

    it('is a labelled modal dialog with a translated close button', async () => {
        const dialog = await openDialog();

        expect(dialog.getAttribute('role')).toBe('dialog');
        expect(dialog.textContent).toContain('Delete the work');
        expect(dialog.textContent).toContain('This cannot be undone.');
        expect(
            dialog
                .querySelector('[data-slot="app-dialog-close"]')
                ?.getAttribute('aria-label'),
        ).toBe('Close');
    });

    it('closes on Escape and returns focus to the trigger', async () => {
        const dialog = await openDialog();

        expect(dialog.contains(document.activeElement)).toBe(true);

        pressEscape();
        await settle();

        expect(panel()).toBeNull();
        expect(document.activeElement?.id).toBe('opener');
    });

    it('ignores Escape while an action is in progress', async () => {
        await openDialog(true);

        pressEscape();
        await settle();

        expect(panel()).not.toBeNull();
        expect(
            panel()!
                .querySelector('[data-slot="app-dialog-close"]')
                ?.hasAttribute('disabled'),
        ).toBe(true);

        await wrapper!.setProps({ busy: false });
        pressEscape();
        await settle();

        expect(panel()).toBeNull();
    });
});
