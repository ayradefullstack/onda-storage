<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { oeuvreLabel } from '@/components/oeuvre/label';
import {
    DataTable,
    DataTableToolbar,
    DataTableSearch,
    DataTableFilterPills,
    DataTableStatusBadge,
    DataTablePagination,
    DataTableEmpty,
} from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBytes, formatDate } from '@/lib/format';
import { dashboard } from '@/routes/admin';
import { show as authorShow } from '@/routes/admin/authors';
import {
    index as oeuvresIndex,
    show as oeuvreShow,
} from '@/routes/admin/oeuvres';

interface OeuvreRow {
    uuid: string;
    title: string | null;
    college_name: string | null;
    status: string;
    author: { uuid: string; name: string } | null;
    files_count: number;
    files_size_bytes: number;
    all_ready: boolean;
    has_blocking_file: boolean;
    created_at: string;
}

const props = defineProps<{
    oeuvres: {
        data: OeuvreRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        search: string;
        status: string;
        author: string;
        from: string;
        to: string;
    };
    statuses: string[];
}>();

const { t, locale } = useI18n();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard() },
            { title: 'Works', href: oeuvresIndex() },
        ],
    },
});

const search = ref(props.filters.search);
const author = ref(props.filters.author);
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function applyFilters(overrides: Record<string, string> = {}): void {
    router.get(
        oeuvresIndex.url({
            query: {
                search: search.value,
                author: author.value,
                from: from.value,
                to: to.value,
                status: props.filters.status,
                ...overrides,
            },
        }),
        {},
        { preserveState: true, replace: true },
    );
}

const hasResults = computed(() => props.oeuvres.data.length > 0);

const hasActiveFilters = computed(() =>
    Boolean(
        search.value?.trim() ||
        author.value?.trim() ||
        props.filters.status ||
        from.value ||
        to.value,
    ),
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

    if (author.value?.trim()) {
        pills.push({
            key: 'author',
            label: t('admin.oeuvres.colAuthor', 'Auteur'),
            value: author.value.trim(),
        });
    }

    if (props.filters.status) {
        pills.push({
            key: 'status',
            label: t('admin.oeuvres.colStatus', 'Statut'),
            value: t(`oeuvres.status.${props.filters.status}`),
        });
    }

    if (from.value || to.value) {
        pills.push({
            key: 'date',
            label: t('common.date', 'Date'),
            value: `${from.value || '...'} → ${to.value || '...'}`,
        });
    }

    return pills;
});

function removeFilter(key: string): void {
    if (key === 'search') {
        search.value = '';
        applyFilters({ search: '' });
    } else if (key === 'author') {
        author.value = '';
        applyFilters({ author: '' });
    } else if (key === 'status') {
        applyFilters({ status: '' });
    } else if (key === 'date') {
        from.value = '';
        to.value = '';
        applyFilters({ from: '', to: '' });
    }
}

function clearFilters(): void {
    search.value = '';
    author.value = '';
    from.value = '';
    to.value = '';
    applyFilters({ search: '', author: '', status: '', from: '', to: '' });
}
</script>

<template>
    <Head :title="t('admin.oeuvres.title')" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ t('admin.oeuvres.title') }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.oeuvres.subtitle') }}
            </p>
        </div>

        <DataTable>
            <template #toolbar>
                <DataTableToolbar
                    :title="t('admin.oeuvres.title')"
                    :count="oeuvres.total"
                >
                    <template #search>
                        <DataTableSearch
                            v-model="search"
                            :placeholder="t('admin.oeuvres.searchPlaceholder')"
                            class="w-full sm:w-60"
                            @submit="applyFilters()"
                            @clear="applyFilters({ search: '' })"
                        />
                    </template>

                    <template #filters>
                        <Input
                            v-model="author"
                            class="h-9 w-40 text-xs"
                            :placeholder="t('admin.oeuvres.authorPlaceholder')"
                            @keyup.enter="applyFilters()"
                            @blur="applyFilters()"
                        />

                        <Select
                            :model-value="filters.status"
                            @update:model-value="
                                (v) => applyFilters({ status: String(v ?? '') })
                            "
                        >
                            <SelectTrigger class="h-9 w-40 text-xs">
                                <SelectValue
                                    :placeholder="
                                        t('admin.oeuvres.allStatuses')
                                    "
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">{{
                                    t('admin.oeuvres.allStatuses')
                                }}</SelectItem>
                                <SelectItem
                                    v-for="status in statuses"
                                    :key="status"
                                    :value="status"
                                >
                                    {{ t(`oeuvres.status.${status}`) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <div class="flex items-center gap-1.5">
                            <Input
                                v-model="from"
                                type="date"
                                class="h-9 w-36 text-xs"
                                @change="applyFilters()"
                            />
                            <span class="text-xs text-muted-foreground">{{
                                t('admin.oeuvres.dateRangeTo')
                            }}</span>
                            <Input
                                v-model="to"
                                type="date"
                                class="h-9 w-36 text-xs"
                                @change="applyFilters()"
                            />
                        </div>
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
                :title="t('admin.oeuvres.empty')"
                :description="t('admin.oeuvres.emptyDescription')"
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
                            {{ t('admin.oeuvres.colTitle') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            {{ t('admin.oeuvres.colAuthor') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            {{ t('admin.oeuvres.colStatus') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            {{ t('admin.oeuvres.colFiles') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            {{ t('admin.oeuvres.colSize') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            {{ t('admin.oeuvres.colSubmitted') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            {{ t('admin.oeuvres.colReady') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    <tr
                        v-for="(oeuvre, index) in oeuvres.data"
                        :key="oeuvre.uuid"
                        class="transition-colors duration-150 hover:bg-muted/40"
                    >
                        <td
                            class="w-12 px-3 py-3.5 text-center text-xs font-medium text-muted-foreground"
                        >
                            {{ (oeuvres.from ?? 1) + index }}
                        </td>
                        <td class="px-4 py-3.5">
                            <Link
                                :href="oeuvreShow(oeuvre.uuid)"
                                class="font-medium text-foreground transition-colors hover:text-primary"
                            >
                                <bdi>{{
                                    oeuvreLabel(
                                        oeuvre,
                                        locale,
                                        t('oeuvres.untitled'),
                                    )
                                }}</bdi>
                            </Link>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <Link
                                v-if="oeuvre.author"
                                :href="authorShow(oeuvre.author.uuid)"
                                class="hover:text-foreground hover:underline"
                            >
                                {{ oeuvre.author.name }}
                            </Link>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1.5">
                                <DataTableStatusBadge
                                    :status="oeuvre.status"
                                    :label="
                                        t(`oeuvres.status.${oeuvre.status}`)
                                    "
                                />
                                <span
                                    v-if="oeuvre.has_blocking_file"
                                    class="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-2 py-0.5 text-[11px] font-medium text-destructive"
                                    :title="t('admin.oeuvres.hasBlockingFile')"
                                >
                                    <AlertTriangle class="size-3" />
                                    {{ t('admin.oeuvres.hasBlockingFile') }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <bdi dir="ltr">{{ oeuvre.files_count }}</bdi>
                        </td>
                        <td class="px-4 py-3">
                            <bdi dir="ltr">{{
                                formatBytes(oeuvre.files_size_bytes, locale)
                            }}</bdi>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <bdi dir="ltr">{{
                                formatDate(oeuvre.created_at, locale)
                            }}</bdi>
                        </td>
                        <td class="px-4 py-3">
                            <CheckCircle2
                                v-if="oeuvre.all_ready"
                                class="size-4 text-emerald-600 dark:text-emerald-400"
                            />
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <template #pagination>
                <DataTablePagination :paginated="oeuvres" show-page-size />
            </template>
        </DataTable>
    </div>
</template>
