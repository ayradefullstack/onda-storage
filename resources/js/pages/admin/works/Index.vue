<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/admin/EmptyState.vue';
import Pagination from '@/components/admin/Pagination.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
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
import { index as worksIndex, show as workShow } from '@/routes/admin/works';

interface WorkRow {
    uuid: string;
    title: string;
    status: string;
    author: { uuid: string; name: string } | null;
    files_count: number;
    files_size_bytes: number;
    all_ready: boolean;
    has_blocking_file: boolean;
    created_at: string;
}

const props = defineProps<{
    works: {
        data: WorkRow[];
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
            { title: 'Works', href: worksIndex() },
        ],
    },
});

const search = ref(props.filters.search);
const author = ref(props.filters.author);
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function applyFilters(overrides: Record<string, string> = {}): void {
    router.get(
        worksIndex.url({
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

const hasResults = computed(() => props.works.data.length > 0);
</script>

<template>
    <Head :title="t('admin.works.title')" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ t('admin.works.title') }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ t('admin.works.subtitle') }}
            </p>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div class="relative w-full max-w-xs">
                <Search
                    class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    class="ps-9"
                    :placeholder="t('admin.works.searchPlaceholder')"
                    @keyup.enter="applyFilters()"
                    @blur="applyFilters()"
                />
            </div>

            <Input
                v-model="author"
                class="w-48"
                :placeholder="t('admin.works.authorPlaceholder')"
                @keyup.enter="applyFilters()"
                @blur="applyFilters()"
            />

            <Select
                :model-value="filters.status"
                @update:model-value="
                    (v) => applyFilters({ status: String(v ?? '') })
                "
            >
                <SelectTrigger class="w-48">
                    <SelectValue :placeholder="t('admin.works.allStatuses')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="">{{
                        t('admin.works.allStatuses')
                    }}</SelectItem>
                    <SelectItem
                        v-for="status in statuses"
                        :key="status"
                        :value="status"
                    >
                        {{ t(`works.status.${status}`) }}
                    </SelectItem>
                </SelectContent>
            </Select>

            <div class="flex items-center gap-2">
                <Input
                    v-model="from"
                    type="date"
                    class="w-40"
                    @change="applyFilters()"
                />
                <span class="text-xs text-muted-foreground">{{
                    t('admin.works.dateRangeTo')
                }}</span>
                <Input
                    v-model="to"
                    type="date"
                    class="w-40"
                    @change="applyFilters()"
                />
            </div>
        </div>

        <EmptyState
            v-if="!hasResults"
            :title="t('admin.works.empty')"
            :description="t('admin.works.emptyDescription')"
        />

        <div v-else class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead>
                    <tr
                        class="border-b border-border text-xs text-muted-foreground uppercase"
                    >
                        <th class="px-3 py-2 text-start font-medium">
                            {{ t('admin.works.colTitle') }}
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            {{ t('admin.works.colAuthor') }}
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            {{ t('admin.works.colStatus') }}
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            {{ t('admin.works.colFiles') }}
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            {{ t('admin.works.colSize') }}
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            {{ t('admin.works.colSubmitted') }}
                        </th>
                        <th class="px-3 py-2 text-start font-medium">
                            {{ t('admin.works.colReady') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="work in works.data"
                        :key="work.uuid"
                        class="border-b border-border last:border-0 hover:bg-accent/50"
                    >
                        <td class="px-3 py-2">
                            <Link
                                :href="workShow(work.uuid)"
                                class="font-medium hover:underline"
                            >
                                {{ work.title }}
                            </Link>
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                v-if="work.author"
                                :href="authorShow(work.author.uuid)"
                                class="hover:underline"
                            >
                                {{ work.author.name }}
                            </Link>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-1.5">
                                <StatusBadge
                                    kind="work"
                                    :status="work.status"
                                />
                                <span
                                    v-if="work.has_blocking_file"
                                    class="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive"
                                    :title="t('admin.works.hasBlockingFile')"
                                >
                                    <AlertTriangle class="size-3" />
                                    {{ t('admin.works.hasBlockingFile') }}
                                </span>
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <bdi dir="ltr">{{ work.files_count }}</bdi>
                        </td>
                        <td class="px-3 py-2">
                            <bdi dir="ltr">{{
                                formatBytes(work.files_size_bytes, locale)
                            }}</bdi>
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">
                            <bdi dir="ltr">{{
                                formatDate(work.created_at, locale)
                            }}</bdi>
                        </td>
                        <td class="px-3 py-2">
                            <CheckCircle2
                                v-if="work.all_ready"
                                class="size-4 text-primary"
                            />
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination
            :links="works.links"
            :from="works.from"
            :to="works.to"
            :total="works.total"
        />
    </div>
</template>
