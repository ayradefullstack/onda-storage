<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Info } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    DataTableFilterPills,
    DataTableSearch,
} from '@/components/ui/data-table';
import type { ActiveFilter } from '@/components/ui/data-table';
import type { ListFilters } from '@/composables/useListFilters';

/**
 * The frame every reference-data tab shares: the tab bar with its row
 * counts, the search box, and the flag legend.
 *
 * The legend is not decoration. `status`, `is_disabled` and
 * `available_in_registration` overlap enough that an officer will
 * reasonably assume two of them do the same thing — they do not, and the
 * seeded data depends on all three. So each carries its own one-line
 * explanation of what it actually does, taken from how the cascade and
 * StoreOeuvreRequest read them today.
 */
interface Tab {
    key: string;
    route: string;
    count: number;
}

const props = defineProps<{
    tabs: Tab[];
    active: string;
    /**
     * The page's useListFilters: the search box here and the page's own
     * selects (#filters slot) write to the same query, so a search keeps
     * the college/type filter and a select keeps the search.
     */
    list: ListFilters<{ search: string }>;
    /** Which of the three flags this tab actually shows. */
    legend?: Array<'status' | 'is_disabled' | 'available_in_registration'>;
}>();

const { t } = useI18n();

const tabHref = (tab: Tab) => `/admin/referentiel/${tab.key}`;

const activeFilters = computed<ActiveFilter[]>(() => {
    const search = props.list.values.search.trim();

    if (search === '') {
        return [];
    }

    return [
        {
            key: 'search',
            label: t('common.search', 'Recherche'),
            value: `"${search}"`,
        },
    ];
});

const clearSearch = () => props.list.set({ search: '' });
</script>

<template>
    <Head :title="t(`admin.referentiel.tabs.${active}`)" />

    <div class="mx-auto w-full max-w-[110rem] space-y-5 p-4 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('admin.referentiel.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ t('admin.referentiel.subtitle') }}
                </p>
            </div>
            <!-- Each tab's "create" button. -->
            <slot name="actions" />
        </div>

        <!-- One route per tab: a deep link works and a refresh stays put. -->
        <nav
            class="flex flex-wrap items-center gap-1 border-b border-border pb-px"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tabHref(tab)"
                :class="[
                    'flex items-center gap-2 rounded-t-lg border-b-2 px-3.5 py-2 text-sm font-medium transition-colors',
                    tab.key === active
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground',
                ]"
            >
                <span>{{ t(`admin.referentiel.tabs.${tab.key}`) }}</span>
                <span
                    :class="[
                        'rounded-full px-1.5 py-0.5 font-mono text-[10px]',
                        tab.key === active
                            ? 'bg-primary/15 text-primary'
                            : 'bg-muted text-muted-foreground',
                    ]"
                >
                    <bdi>{{ tab.count }}</bdi>
                </span>
            </Link>
        </nav>

        <div class="flex flex-col gap-2.5">
            <div class="flex flex-wrap items-center gap-2.5">
                <DataTableSearch
                    :model-value="list.values.search"
                    :placeholder="t('admin.referentiel.searchPlaceholder')"
                    :debounce="0"
                    :loading="list.loading.value"
                    class="w-full sm:max-w-xs"
                    @update:model-value="(v) => list.input('search', v)"
                    @submit="list.apply()"
                    @clear="clearSearch"
                />
                <slot name="filters" />
            </div>

            <DataTableFilterPills
                v-if="activeFilters.length > 0"
                :filters="activeFilters"
                @remove="clearSearch"
                @clear-all="list.clear()"
            />
        </div>

        <!-- What the three overlapping flags actually do. -->
        <div
            v-if="legend && legend.length > 0"
            class="flex flex-col gap-1.5 rounded-lg border border-border/80 bg-muted/30 p-3 text-xs"
        >
            <p class="flex items-center gap-1.5 font-medium text-foreground">
                <Info class="size-3.5" />
                {{ t('admin.referentiel.legend.title') }}
            </p>
            <p v-for="flag in legend" :key="flag" class="text-muted-foreground">
                <span class="font-medium text-foreground"
                    >{{ t(`admin.referentiel.flags.${flag}`) }} —</span
                >
                {{ t(`admin.referentiel.flags.${flag}Help`) }}
            </p>
        </div>

        <div
            data-test="referentiel-list"
            :aria-busy="list.loading.value ? 'true' : undefined"
            :class="[
                'transition-opacity duration-150',
                list.loading.value && 'pointer-events-none opacity-60',
            ]"
        >
            <slot />
        </div>
    </div>
</template>
