<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Info, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Input } from '@/components/ui/input';

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
    /** The tab's own index URL, for search and filter round-trips. */
    indexUrl: string;
    search: string;
    /** Which of the three flags this tab actually shows. */
    legend?: Array<'status' | 'is_disabled' | 'available_in_registration'>;
}>();

const { t } = useI18n();

const query = ref(props.search);

// Debounced: a search should not fire a request per keystroke. Only this
// tab's props are refreshed — the tab counts do not move as you type.
let timer: ReturnType<typeof setTimeout> | undefined;

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            props.indexUrl,
            query.value.trim() !== '' ? { search: query.value.trim() } : {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['rows', 'filters'],
            },
        );
    }, 350);
});

const tabHref = (tab: Tab) => `/admin/referentiel/${tab.key}`;
</script>

<template>
    <Head :title="t(`admin.referentiel.tabs.${active}`)" />

    <div class="mx-auto w-full max-w-[110rem] space-y-5 p-4 sm:p-6">
        <div class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">
                {{ t('admin.referentiel.title') }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ t('admin.referentiel.subtitle') }}
            </p>
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

        <div class="flex flex-wrap items-center gap-2.5">
            <div class="relative w-full sm:max-w-xs">
                <Search
                    class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="query"
                    type="search"
                    :placeholder="t('admin.referentiel.searchPlaceholder')"
                    class="h-9 ps-9 text-sm"
                />
            </div>
            <slot name="filters" />
        </div>

        <!-- What the three overlapping flags actually do. -->
        <div
            v-if="legend && legend.length > 0"
            class="flex flex-col gap-1.5 rounded-lg border border-border/80 bg-muted/30 p-3 text-xs"
        >
            <p
                class="flex items-center gap-1.5 font-medium text-foreground"
            >
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

        <slot />
    </div>
</template>
