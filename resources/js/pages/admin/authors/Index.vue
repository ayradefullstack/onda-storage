<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/admin/EmptyState.vue';
import Pagination from '@/components/admin/Pagination.vue';
import QuotaBar from '@/components/admin/QuotaBar.vue';
import { Input } from '@/components/ui/input';
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
    works_count: number;
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

type SortColumn = 'activity' | 'quota' | 'works' | 'files' | 'name';

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

        <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-full max-w-xs">
                <Search
                    class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    class="ps-9"
                    :placeholder="t('admin.authors.searchPlaceholder')"
                    @keyup.enter="applyFilters()"
                    @blur="applyFilters()"
                />
            </div>

            <Select
                :model-value="wilayaFilter"
                @update:model-value="
                    (v) => {
                        wilayaFilter = String(v ?? '');
                        applyFilters();
                    }
                "
            >
                <SelectTrigger class="w-48">
                    <SelectValue :placeholder="t('admin.authors.allWilayas')" />
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
        </div>

        <EmptyState
            v-if="!hasResults"
            :title="t('admin.authors.empty')"
            :description="t('admin.authors.emptyDescription')"
        />

        <div v-else class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead>
                    <tr
                        class="border-b border-border text-xs text-muted-foreground uppercase"
                    >
                        <th class="px-3 py-2 text-start font-medium">
                            <Link
                                :href="sortHref('name')"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                {{ t('admin.authors.colName') }}
                                <component
                                    :is="sortIcon('name')"
                                    class="size-3"
                                />
                            </Link>
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            {{ t('admin.authors.colWilaya') }}
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            <Link
                                :href="sortHref('works')"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                {{ t('admin.authors.colWorks') }}
                                <component
                                    :is="sortIcon('works')"
                                    class="size-3"
                                />
                            </Link>
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            <Link
                                :href="sortHref('files')"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                {{ t('admin.authors.colFiles') }}
                                <component
                                    :is="sortIcon('files')"
                                    class="size-3"
                                />
                            </Link>
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            <Link
                                :href="sortHref('quota')"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                {{ t('admin.authors.colStorage') }}
                                <component
                                    :is="sortIcon('quota')"
                                    class="size-3"
                                />
                            </Link>
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            <Link
                                :href="sortHref('activity')"
                                class="inline-flex items-center gap-1 hover:text-foreground"
                            >
                                {{ t('admin.authors.colLastActivity') }}
                                <component
                                    :is="sortIcon('activity')"
                                    class="size-3"
                                />
                            </Link>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="author in authors.data"
                        :key="author.uuid"
                        class="border-b border-border last:border-0 hover:bg-accent/50"
                    >
                        <td class="px-3 py-2">
                            <Link
                                :href="authorShow(author.uuid)"
                                class="font-medium hover:underline"
                            >
                                {{ author.name }}
                            </Link>
                            <div class="text-xs text-muted-foreground">
                                <bdi dir="ltr">{{ author.email }}</bdi>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">
                            {{
                                author.wilaya ? wilayaLabel(author.wilaya) : '—'
                            }}
                        </td>
                        <td class="px-3 py-2">
                            <bdi dir="ltr">{{ author.works_count }}</bdi>
                        </td>
                        <td class="px-3 py-2">
                            <bdi dir="ltr">{{ author.files_count }}</bdi>
                        </td>
                        <td class="min-w-40 px-3 py-2">
                            <QuotaBar
                                :used-bytes="author.quota_used_bytes"
                                :limit-bytes="author.quota_limit_bytes"
                            />
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">
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
        </div>

        <Pagination
            :links="authors.links"
            :from="authors.from"
            :to="authors.to"
            :total="authors.total"
        />
    </div>
</template>
