<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import QuotaBar from '@/components/admin/QuotaBar.vue';
import {
    DataTable,
    DataTableToolbar,
    DataTableSearch,
    DataTableFilterPills,
    DataTablePagination,
    DataTableEmpty,
} from '@/components/ui/data-table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/format';
import { dashboard } from '@/routes/admin';
import {
    index as authorsIndex,
    show as authorShow,
} from '@/routes/admin/authors';

interface AuthorRow {
    uuid: string;
    name: string;
    email: string;
    wilaya: { name_fr: string; name_ar: string } | null;
    oeuvres_count: number;
    files_count: number;
    quota_used_bytes: number;
    quota_limit_bytes: number;
    last_activity_at: string | null;
}

interface WilayaOption {
    uuid: string;
    name_fr: string;
    name_ar: string;
}

const props = defineProps<{
    authors: {
        data: AuthorRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        search: string;
        wilaya: string;
        sort: string;
        direction: 'asc' | 'desc';
    };
    wilayas: WilayaOption[];
}>();

const { t, locale } = useI18n();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard() },
            { title: 'Authors', href: authorsIndex() },
        ],
    },
});

const search = ref(props.filters.search);
const wilayaFilter = ref(props.filters.wilaya);

function wilayaLabel(w: { name_fr: string; name_ar: string }): string {
    return locale.value === 'ar' ? w.name_ar : w.name_fr;
}

function applyFilters(overrides: Record<string, string> = {}): void {
    router.get(
        authorsIndex.url({
            query: {
                search: search.value,
                wilaya: wilayaFilter.value,
                sort: props.filters.sort,
                direction: props.filters.direction,
                ...overrides,
            },
        }),
        {},
        { preserveState: true, replace: true },
    );
}

type SortColumn = 'activity' | 'quota' | 'oeuvres' | 'files' | 'name';

function sortHref(column: SortColumn): string {
    const direction =
        props.filters.sort === column && props.filters.direction === 'desc'
            ? 'asc'
            : 'desc';

    return authorsIndex.url({
        query: {
            search: search.value,
            wilaya: wilayaFilter.value,
            sort: column,
            direction,
        },
    });
}

function sortIcon(column: SortColumn) {
    if (props.filters.sort !== column) {
        return ArrowUpDown;
    }

    return props.filters.direction === 'desc' ? ArrowDown : ArrowUp;
}

const hasResults = computed(() => props.authors.data.length > 0);

const hasActiveFilters = computed(() =>
    Boolean(search.value?.trim() || wilayaFilter.value),
);

const activeFilters = computed(() => {
    const pills: { key: string; label: string; value: string }[] = [];

    if (search.value?.trim()) {
        pills.push({
            key: 'search',
            label: t('common.search', 'Recherche'),
            value: `"${search.value.trim()}"`,
        });
    }

    if (wilayaFilter.value) {
        const found = props.wilayas.find((w) => w.uuid === wilayaFilter.value);
        pills.push({
            key: 'wilaya',
            label: t('admin.authors.colWilaya', 'Wilaya'),
            value: found ? wilayaLabel(found) : wilayaFilter.value,
        });
    }

    return pills;
});

function removeFilter(key: string): void {
    if (key === 'search') {
        search.value = '';
        applyFilters({ search: '' });
    } else if (key === 'wilaya') {
        wilayaFilter.value = '';
        applyFilters({ wilaya: '' });
    }
}

function clearFilters(): void {
    search.value = '';
    wilayaFilter.value = '';
    applyFilters({ search: '', wilaya: '' });
}
</script>

<template>
    <Head :title="t('admin.authors.title')" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ t('admin.authors.title') }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.authors.subtitle') }}
            </p>
        </div>

        <DataTable>
            <template #toolbar>
                <DataTableToolbar
                    :title="t('admin.authors.title')"
                    :count="authors.total"
                >
                    <template #search>
                        <DataTableSearch
                            v-model="search"
                            :placeholder="t('admin.authors.searchPlaceholder')"
                            class="w-full sm:w-64"
                            @submit="applyFilters()"
                            @clear="applyFilters({ search: '' })"
                        />
                    </template>

                    <template #filters>
                        <Select
                            :model-value="wilayaFilter"
                            @update:model-value="
                                (v) => {
                                    wilayaFilter = String(v ?? '');
                                    applyFilters();
                                }
                            "
                        >
                            <SelectTrigger size="sm" class="h-9 w-44">
                                <SelectValue
                                    :placeholder="t('admin.authors.allWilayas')"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">{{
                                    t('admin.authors.allWilayas')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="w in wilayas"
                                    :key="w.uuid"
                                    :value="w.uuid"
                                >
                                    {{ wilayaLabel(w) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </template>

                    <template #filter-pills>
                        <DataTableFilterPills
                            :filters="activeFilters"
                            @remove="removeFilter"
                            @clear-all="clearFilters"
                        />
                    </template>
                </DataTableToolbar>
            </template>

            <!-- Empty State -->
            <DataTableEmpty
                v-if="!hasResults"
                :title="t('admin.authors.empty')"
                :description="t('admin.authors.emptyDescription')"
                :has-active-filters="hasActiveFilters"
                @clear-filters="clearFilters"
            />

            <!-- Enterprise Table -->
            <table v-else class="w-full border-collapse text-xs">
                <thead>
                    <tr
                        class="border-b border-border/70 bg-muted/40 font-semibold text-muted-foreground"
                    >
                        <th class="w-12 px-3 py-3 text-center font-medium">
                            #
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            <Link
                                :href="sortHref('name')"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                {{ t('admin.authors.colName') }}
                                <component
                                    :is="sortIcon('name')"
                                    :stroke-width="1.75"
                                    class="size-3"
                                />
                            </Link>
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            {{ t('admin.authors.colWilaya') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            <Link
                                :href="sortHref('oeuvres')"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                {{ t('admin.authors.colOeuvres') }}
                                <component
                                    :is="sortIcon('oeuvres')"
                                    :stroke-width="1.75"
                                    class="size-3"
                                />
                            </Link>
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            <Link
                                :href="sortHref('files')"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                {{ t('admin.authors.colFiles') }}
                                <component
                                    :is="sortIcon('files')"
                                    :stroke-width="1.75"
                                    class="size-3"
                                />
                            </Link>
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            <Link
                                :href="sortHref('quota')"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                {{ t('admin.authors.colStorage') }}
                                <component
                                    :is="sortIcon('quota')"
                                    :stroke-width="1.75"
                                    class="size-3"
                                />
                            </Link>
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            <Link
                                :href="sortHref('activity')"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                {{ t('admin.authors.colLastActivity') }}
                                <component
                                    :is="sortIcon('activity')"
                                    :stroke-width="1.75"
                                    class="size-3"
                                />
                            </Link>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    <tr
                        v-for="(author, index) in authors.data"
                        :key="author.uuid"
                        class="transition-colors duration-150 hover:bg-muted/40"
                    >
                        <td
                            class="w-12 px-3 py-3.5 text-center text-xs font-medium text-muted-foreground"
                        >
                            {{ (authors.from ?? 1) + index }}
                        </td>
                        <td class="px-4 py-3.5">
                            <Link
                                :href="authorShow(author.uuid)"
                                class="font-medium text-foreground transition-colors hover:text-primary"
                            >
                                {{ author.name }}
                            </Link>
                            <div class="text-xs text-muted-foreground">
                                <bdi dir="ltr">{{ author.email }}</bdi>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{
                                author.wilaya ? wilayaLabel(author.wilaya) : '—'
                            }}
                        </td>
                        <td class="px-4 py-3">
                            <bdi dir="ltr">{{ author.oeuvres_count }}</bdi>
                        </td>
                        <td class="px-4 py-3">
                            <bdi dir="ltr">{{ author.files_count }}</bdi>
                        </td>
                        <td class="min-w-40 px-4 py-3">
                            <QuotaBar
                                :used-bytes="author.quota_used_bytes"
                                :limit-bytes="author.quota_limit_bytes"
                            />
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <bdi dir="ltr">{{
                                author.last_activity_at
                                    ? formatDate(
                                          author.last_activity_at,
                                          locale,
                                      )
                                    : t('admin.authors.noActivity')
                            }}</bdi>
                        </td>
                    </tr>
                </tbody>
            </table>

            <template #pagination>
                <DataTablePagination :paginated="authors" show-page-size />
            </template>
        </DataTable>
    </div>
</template>
