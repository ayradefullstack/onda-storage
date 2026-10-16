// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import fr from '@/locales/fr.json';

vi.mock('@inertiajs/vue3', () => ({ Link: { render: () => null } }));

const { default: DataTableEmpty } = await import('./DataTableEmpty.vue');
const { default: EmptyState } = await import(
    '@/components/ui/empty-state/EmptyState.vue'
);

/**
 * Both components used to declare a lucide icon as a `withDefaults`
 * default. Vue calls a function default as a factory, so rendering either
 * one WITHOUT an explicit icon threw "Cannot destructure property 'slots'
 * of 'undefined'" — on every list whose filter matched nothing.
 */
function options() {
    const errors: unknown[] = [];

    return {
        errors,
        global: {
            plugins: [
                createI18n({
                    legacy: false,
                    locale: 'fr',
                    missingWarn: false,
                    messages: { fr },
                }),
            ],
            config: {
                errorHandler: (error: unknown) => {
                    errors.push(error);
                },
            },
        },
    };
}

describe('DataTableEmpty', () => {
    it('renders its default icon without an explicit `icon` prop', () => {
        const { errors, global } = options();
        const wrapper = mount(DataTableEmpty, { global });

        expect(errors).toEqual([]);
        expect(wrapper.find('svg').exists()).toBe(true);
        expect(wrapper.text()).toContain('Aucun résultat trouvé');
    });

    it('offers "Effacer les filtres" and emits clear-filters', async () => {
        const { global } = options();
        const wrapper = mount(DataTableEmpty, {
            props: { hasActiveFilters: true },
            global,
        });

        const button = wrapper.find('[data-test="list-empty-clear"]');
        expect(button.text()).toBe('Effacer les filtres');

        await button.trigger('click');
        expect(wrapper.emitted('clear-filters')).toHaveLength(1);
    });

    it('has nothing to clear when no filter is active', () => {
        const { global } = options();
        const wrapper = mount(DataTableEmpty, {
            props: { hasActiveFilters: false },
            global,
        });

        expect(wrapper.find('[data-test="list-empty-clear"]').exists()).toBe(
            false,
        );
    });
});

describe('ui EmptyState', () => {
    it('renders its default icon without an explicit `icon` prop', () => {
        const { errors, global } = options();
        const wrapper = mount(EmptyState, {
            props: { title: 'Rien' },
            global,
        });

        expect(errors).toEqual([]);
        expect(wrapper.find('svg').exists()).toBe(true);
    });
});
