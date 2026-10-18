// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { defineComponent, nextTick, ref } from 'vue';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import AppCombobox from './AppCombobox.vue';
import type { ComboboxOption } from './AppCombobox.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en },
    missingWarn: false,
    fallbackWarn: false,
});

const OPTIONS: ComboboxOption[] = [
    { value: 1, label: 'Musique', labelAr: 'موسيقى', badge: 'MUS' },
    { value: 2, label: 'Oeuvres dramatiques', labelAr: 'الأعمال الدرامية' },
    { value: 3, label: 'Arts graphiques', labelAr: 'الفنون التشكيلية' },
    { value: 4, label: 'Éditeurs de musique', labelAr: 'ناشرو الموسيقى' },
    { value: 5, label: 'Sétif', labelAr: 'سطيف' },
    { value: 6, label: 'Béjaïa', labelAr: 'بجاية' },
    { value: 7, label: 'Oum El Bouaghi', labelAr: 'أم البواقي' },
    { value: 8, label: 'Ghardaïa', labelAr: 'غرداية', disabled: true },
];

const Host = defineComponent({
    components: { AppCombobox },
    props: { initial: { type: Number, default: null } },
    setup(props) {
        return { value: ref<number | string | null>(props.initial), OPTIONS };
    },
    template: `<AppCombobox v-model="value" name="college" id="college" :options="OPTIONS" placeholder="Choose" />`,
});

let wrapper: VueWrapper | null = null;

const settle = async (): Promise<void> => {
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 30));
    await nextTick();
};

function render(initial: number | null = null): void {
    wrapper = mount(Host, {
        props: { initial: initial as number },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
}

const trigger = (): HTMLButtonElement =>
    document.querySelector<HTMLButtonElement>(
        '[data-slot="app-combobox-trigger"]',
    )!;

const search = (): HTMLInputElement | null =>
    document.querySelector<HTMLInputElement>(
        '[data-slot="app-combobox-search"]',
    );

const labels = (): string[] =>
    Array.from(
        document.querySelectorAll('[data-slot="app-combobox-option"]'),
    ).map((option) => option.querySelector('bdi')!.textContent!.trim());

async function toggle(): Promise<void> {
    trigger().click();
    await settle();
}

async function type(text: string): Promise<void> {
    const input = search()!;

    input.value = text;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
});

describe('AppCombobox', () => {
    it('lists every option when opened, with the menu closed until then', async () => {
        render();

        expect(labels()).toEqual([]);
        expect(trigger().getAttribute('aria-expanded')).toBe('false');
        expect(trigger().textContent).toContain('Choose');

        await toggle();

        expect(trigger().getAttribute('aria-expanded')).toBe('true');
        expect(labels()).toHaveLength(OPTIONS.length);
    });

    it('filters French labels without regard to case or accents', async () => {
        render();
        await toggle();

        await type('SETIF');
        expect(labels()).toEqual(['Sétif']);

        await type('editeurs');
        expect(labels()).toEqual(['Éditeurs de musique']);

        await type('bejaia');
        expect(labels()).toEqual(['Béjaïa']);

        await type('musique');
        expect(labels()).toEqual(['Musique', 'Éditeurs de musique']);
    });

    it('filters Arabic labels across hamza and ta marbuta variants', async () => {
        render();
        await toggle();

        await type('ام البواقي');
        expect(labels()).toEqual(['Oum El Bouaghi']);

        await type('غردايه');
        expect(labels()).toEqual(['Ghardaïa']);

        await type('الاعمال');
        expect(labels()).toEqual(['Oeuvres dramatiques']);
    });

    it('shows the empty state, and its reset brings every option back', async () => {
        render();
        await toggle();
        await type('zzzz');

        const empty = document.querySelector(
            '[data-slot="app-combobox-empty"]',
        )!;

        expect(labels()).toEqual([]);
        expect(empty.textContent).toContain('No results');

        empty.querySelector('button')!.click();
        await settle();

        expect(search()!.value).toBe('');
        expect(labels()).toHaveLength(OPTIONS.length);
    });

    it('selects an option, closes, and marks it with a check', async () => {
        render();
        await toggle();

        document
            .querySelectorAll<HTMLElement>(
                '[data-slot="app-combobox-option"]',
            )[2]
            .click();
        await settle();

        expect(labels()).toEqual([]);
        expect(trigger().textContent).toContain('Arts graphiques');
        expect(
            document.querySelector<HTMLInputElement>('input[name="college"]')!
                .value,
        ).toBe('3');

        await toggle();

        const checked = Array.from(
            document.querySelectorAll('[data-slot="app-combobox-option"]'),
        ).filter((option) =>
            option.querySelector('[data-slot="app-combobox-check"]'),
        );

        expect(checked).toHaveLength(1);
        expect(checked[0].textContent).toContain('Arts graphiques');
    });

    /**
     * Regression (ClassificationCascade, oeuvre create page): after a value
     * was chosen, reopening the select listed only that option, because the
     * search query had kept the selected label.
     */
    it('reopens with an empty query and ALL options when a value is already selected', async () => {
        render(4);

        expect(trigger().textContent).toContain('Éditeurs de musique');

        await toggle();

        expect(search()!.value).toBe('');
        expect(labels()).toHaveLength(OPTIONS.length);
    });

    it('drops a typed query when the menu is closed and opened again', async () => {
        render(4);
        await toggle();
        await type('setif');
        expect(labels()).toEqual(['Sétif']);

        await toggle(); // close without choosing
        expect(labels()).toEqual([]);

        await toggle();

        expect(search()!.value).toBe('');
        expect(labels()).toHaveLength(OPTIONS.length);
        expect(trigger().textContent).toContain('Éditeurs de musique');
    });

    it('does not select a disabled option', async () => {
        render(1);
        await toggle();

        document
            .querySelectorAll<HTMLElement>(
                '[data-slot="app-combobox-option"]',
            )[7]
            .click();
        await settle();

        expect(
            document.querySelector<HTMLInputElement>('input[name="college"]')!
                .value,
        ).toBe('1');
    });

    it('clears the selection from its own button', async () => {
        render(1);

        document
            .querySelector<HTMLElement>('[data-slot="app-combobox-clear"]')!
            .click();
        await settle();

        expect(trigger().textContent).toContain('Choose');
        expect(
            document.querySelector('[data-slot="app-combobox-clear"]'),
        ).toBeNull();
    });
});
