<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/admin/EmptyState.vue';
import Pagination from '@/components/admin/Pagination.vue';
import QuotaBar from '@/components/admin/QuotaBar.vue';
import StatCard from '@/components/admin/StatCard.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBytes, formatDate } from '@/lib/format';
import { dashboard } from '@/routes/admin';
import {
    index as authorsIndex,
    show as authorShow,
} from '@/routes/admin/authors';
import { show as workShow } from '@/routes/admin/works';

interface Author {
    uuid: string;
    name: string;
    first_name: string | null;
    last_name: string | null;
    first_name_ar: string | null;
    last_name_ar: string | null;
    email: string;
    phone: string | null;
    wilaya: { name_fr: string; name_ar: string } | null;
    commune: { name_fr: string; name_ar: string } | null;
    email_verified_at: string | null;
    created_at: string;
}

interface WorkRow {
    uuid: string;
    title: string;
    status: string;
    files_count: number;
    files_size_bytes: number;
    created_at: string;
}

const props = defineProps<{
    author: Author;
    quota: { used_bytes: number; limit_bytes: number };
    works: {
        data: WorkRow[];
        links: { url: string | null; label: string; active: boolean }[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { status: string };
    activity: {
        last_deposit_at: string | null;
        status_tally: Record<string, number>;
    };
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

function wilayaLabel(w: { name_fr: string; name_ar: string }): string {
    return locale.value === 'ar' ? w.name_ar : w.name_fr;
}

const STATUS_OPTIONS = ['submitted', 'under_review', 'registered', 'rejected'];

function applyStatusFilter(status: string): void {
    router.get(
        authorShow.url(props.author.uuid, { query: { status } }),
        {},
        { preserveState: true, replace: true },
    );
}

const arabicName = computed(() => {
    const full =
        `${props.author.first_name_ar ?? ''} ${props.author.last_name_ar ?? ''}`.trim();

    return full === '' ? null : full;
});

const hasWorks = computed(() => props.works.data.length > 0);
</script>

<template>
    <Head :title="author.name" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ author.name }}
                </h1>
                <p v-if="arabicName" class="mt-1 text-sm text-muted-foreground">
                    <bdi dir="rtl">{{ arabicName }}</bdi>
                </p>
            </div>
            <Badge :variant="author.email_verified_at ? 'default' : 'outline'">
                {{
                    author.email_verified_at
                        ? t('admin.authors.emailVerified')
                        : t('admin.authors.emailUnverified')
                }}
            </Badge>
        </div>

        <div
            class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4"
        >
            <div class="rounded-lg border border-border bg-card p-4">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('admin.authors.colName') }}
                </p>
                <p class="mt-1">
                    <bdi dir="ltr">{{ author.email }}</bdi>
                </p>
                <p v-if="author.phone" class="text-muted-foreground">
                    <bdi dir="ltr">{{ author.phone }}</bdi>
                </p>
            </div>
            <div class="rounded-lg border border-border bg-card p-4">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('admin.authors.colWilaya') }}
                </p>
                <p class="mt-1">
                    {{ author.wilaya ? wilayaLabel(author.wilaya) : '—' }}
                </p>
                <p v-if="author.commune" class="text-muted-foreground">
                    {{ wilayaLabel(author.commune) }}
                </p>
            </div>
            <div class="rounded-lg border border-border bg-card p-4">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('admin.authors.registeredOn') }}
                </p>
                <p class="mt-1">
                    <bdi dir="ltr">{{
                        formatDate(author.created_at, locale)
                    }}</bdi>
                </p>
            </div>
            <div class="rounded-lg border border-border bg-card p-4">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ t('admin.authors.colStorage') }}
                </p>
                <div class="mt-2">
                    <QuotaBar
                        :used-bytes="quota.used_bytes"
                        :limit-bytes="quota.limit_bytes"
                    />
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatCard
                :label="t('admin.authors.lastDeposit')"
                :value="
                    activity.last_deposit_at
                        ? formatDate(activity.last_deposit_at, locale)
                        : t('admin.authors.noActivity')
                "
            />
            <StatCard
                v-for="status in STATUS_OPTIONS"
                :key="status"
                :label="t(`works.status.${status}`)"
                :value="activity.status_tally[status] ?? 0"
            />
        </div>

        <div>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-medium">
                    {{ t('admin.authors.worksTitle') }}
                </h2>
                <Select
                    :model-value="filters.status"
                    @update:model-value="
                        (v) => applyStatusFilter(String(v ?? ''))
                    "
                >
                    <SelectTrigger class="w-48">
                        <SelectValue
                            :placeholder="t('admin.works.allStatuses')"
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="">{{
                            t('admin.works.allStatuses')
                        }}</SelectItem>
                        <SelectItem
                            v-for="status in STATUS_OPTIONS"
                            :key="status"
                            :value="status"
                        >
                            {{ t(`works.status.${status}`) }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <EmptyState
                v-if="!hasWorks"
                :title="t('admin.authors.noWorks')"
                :description="t('admin.authors.noWorksDescription')"
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
                                <StatusBadge
                                    kind="work"
                                    :status="work.status"
                                />
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
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination
                :links="works.links"
                :from="works.from"
                :to="works.to"
                :total="works.total"
                class="mt-3"
            />
        </div>
    </div>
</template>
