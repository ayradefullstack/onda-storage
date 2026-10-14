<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Eye,
    FileText,
    FolderArchive,
    Inbox,
    RotateCcw,
    SlidersHorizontal,
    User,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { oeuvreLabel } from '@/components/oeuvre/label';
import ExamineButton from '@/components/review/ExamineButton.vue';
import ReviewDecisionDialog from '@/components/review/ReviewDecisionDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DataTableFilterPills,
    DataTablePagination,
    DataTableSearch,
    DataTableStatusBadge,
    TableScroll,
} from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { silent } from '@/composables/useGlobalLoader';
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
    submitted_at: string | null;
}

/** The officers' queue as one filter value: submitted + under review. */
const TO_REVIEW = 'to_review';

/** The statuses a decision can still be taken on: "Examiner" shows. */
const EXAMINABLE = ['submitted', 'under_review'];

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
    counts?: { to_review: number };
}>();

const { t, locale } = useI18n();

// reka-ui refuses an empty-string SelectItem value, so "no status filter"
// travels as this sentinel and is mapped back to '' at the boundary.
const ALL_STATUSES = '__all';

// Search + author + status + date range columns; the empty-state row spans
// all of them.
const COLUMN_COUNT = 9;

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

const toReviewActive = computed(() => props.filters.status === TO_REVIEW);

function toggleToReview(): void {
    applyFilters({ status: toReviewActive.value ? '' : TO_REVIEW });
}

// One dialog for the whole table: "Examiner" on a row points it at that
// oeuvre. It fetches its own summary, so the rows carry nothing for it.
const examining = ref<string | null>(null);
const dialogOpen = ref(false);

function examine(uuid: string): void {
    examining.value = uuid;
    dialogOpen.value = true;
}

// After a decision the rows and the queue count are stale; the filters,
// the page and the scroll position are not. The row's badge pulses because
// its status prop changes under it.
function onDecision(): void {
    router.reload(silent({ only: ['oeuvres', 'counts'] }));
}

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

    <div
        class="mx-auto w-full max-w-7xl animate-in space-y-6 p-4 duration-200 fade-in-50 sm:p-6 lg:p-8"
    >
        <!-- Page Header -->
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="space-y-1">
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-primary/10 text-primary shadow-2xs"
                    >
                        <FolderArchive class="size-5" />
                    </div>
                    <div>
                        <h1
                            class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                        >
                            {{ t('admin.oeuvres.title') }}
                        </h1>
                        <p class="text-xs text-muted-foreground sm:text-sm">
                            {{ t('admin.oeuvres.subtitle') }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Page Header Stats Counter -->
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <div
                    class="inline-flex items-center gap-2.5 rounded-xl border border-border/70 bg-card px-3.5 py-2 text-xs shadow-2xs"
                >
                    <span class="text-muted-foreground"
                        >{{ t('admin.oeuvres.title') }}:</span
                    >
                    <span class="font-bold text-foreground tabular-nums">
                        <bdi dir="ltr">{{ oeuvres.total }}</bdi>
                    </span>
                    <template v-if="counts?.to_review">
                        <span class="h-3 w-px bg-border" aria-hidden="true" />
                        <span
                            class="inline-flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"
                        >
                            <Inbox class="size-3.5" />
                            <bdi dir="ltr">{{ counts.to_review }}</bdi>
                            <span class="hidden sm:inline">{{
                                t('oeuvres.status.to_review')
                            }}</span>
                        </span>
                    </template>
                </div>
            </div>
        </div>

        <!-- CARD 1: FILTERS CARD -->
        <section
            aria-label="Filtres"
            class="space-y-4 rounded-xl border border-border/75 bg-card p-4 shadow-2xs transition-shadow duration-150 sm:p-5"
        >
            <!-- Card Header: Title + Queue Chip + Clear -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex size-7 items-center justify-center rounded-lg bg-muted/80 text-muted-foreground"
                    >
                        <SlidersHorizontal class="size-3.5" />
                    </div>
                    <div>
                        <h2
                            class="text-sm font-semibold tracking-tight text-foreground"
                        >
                            {{ t('admin.oeuvres.filtersTitle', 'تصفية وبحث') }}
                        </h2>
                    </div>
                    <span
                        v-if="hasActiveFilters"
                        class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary"
                    >
                        {{ activeFilters.length }}
                    </span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <!-- Review Filter Quick Toggle -->
                    <button
                        type="button"
                        data-testid="to-review-chip"
                        :aria-pressed="toReviewActive"
                        class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-all outline-none focus-visible:ring-2 focus-visible:ring-ring/40 active:scale-95"
                        :class="
                            toReviewActive
                                ? 'border-primary bg-primary text-primary-foreground shadow-2xs'
                                : 'border-border/80 bg-background text-foreground hover:bg-muted'
                        "
                        @click="toggleToReview"
                    >
                        <Inbox class="size-3.5" />
                        <span>{{ t('oeuvres.status.to_review') }}</span>
                        <span
                            class="rounded-full px-1.5 py-px text-[11px] tabular-nums"
                            :class="
                                toReviewActive
                                    ? 'bg-primary-foreground/20 text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            <bdi dir="ltr">{{ counts?.to_review ?? 0 }}</bdi>
                        </span>
                    </button>

                    <!-- Reset Filters Button (When active) -->
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="inline-flex h-8 cursor-pointer items-center gap-1 rounded-lg px-2.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground active:scale-95"
                        @click="clearFilters"
                    >
                        <RotateCcw class="size-3" />
                        <span>{{ t('admin.oeuvres.resetFilters') }}</span>
                    </button>
                </div>
            </div>

            <!-- Filters Controls Grid -->
            <div
                class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-12 lg:items-center"
            >
                <!-- Search by title -->
                <div class="sm:col-span-2 lg:col-span-4">
                    <DataTableSearch
                        v-model="search"
                        :placeholder="t('admin.oeuvres.searchPlaceholder')"
                        class="w-full max-w-none"
                        @submit="applyFilters()"
                        @clear="applyFilters({ search: '' })"
                    />
                </div>

                <!-- Author Filter -->
                <div class="relative sm:col-span-1 lg:col-span-3">
                    <span
                        class="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-muted-foreground/70"
                        aria-hidden="true"
                    >
                        <User class="size-3.5" />
                    </span>
                    <Input
                        v-model="author"
                        class="h-9 w-full ps-9 text-xs transition-colors focus:border-ring"
                        :placeholder="t('admin.oeuvres.authorPlaceholder')"
                        @keyup.enter="applyFilters()"
                        @blur="applyFilters()"
                    />
                </div>

                <!-- Status Filter -->
                <div class="sm:col-span-1 lg:col-span-2">
                    <Select
                        :model-value="filters.status || ALL_STATUSES"
                        @update:model-value="
                            (v) =>
                                applyFilters({
                                    status:
                                        !v || v === ALL_STATUSES
                                            ? ''
                                            : String(v),
                                })
                        "
                    >
                        <SelectTrigger class="h-9 w-full text-xs">
                            <SelectValue
                                :placeholder="t('admin.oeuvres.allStatuses')"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALL_STATUSES">
                                {{ t('admin.oeuvres.allStatuses') }}
                            </SelectItem>
                            <SelectItem
                                v-for="status in statuses"
                                :key="status"
                                :value="status"
                            >
                                {{ t(`oeuvres.status.${status}`) }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Date Range (From - To) -->
                <div
                    class="flex items-center gap-1.5 sm:col-span-2 lg:col-span-3"
                >
                    <div class="relative w-full">
                        <Input
                            v-model="from"
                            type="date"
                            class="h-9 w-full text-xs"
                            :title="t('admin.oeuvres.dateRangeFrom')"
                            @change="applyFilters()"
                        />
                    </div>
                    <span class="shrink-0 px-0.5 text-xs text-muted-foreground">
                        {{ t('admin.oeuvres.dateRangeTo') }}
                    </span>
                    <div class="relative w-full">
                        <Input
                            v-model="to"
                            type="date"
                            class="h-9 w-full text-xs"
                            :title="t('admin.oeuvres.dateRangeTo')"
                            @change="applyFilters()"
                        />
                    </div>
                </div>
            </div>

            <!-- Filter Pills -->
            <div
                v-if="hasActiveFilters"
                class="border-t border-border/50 pt-2.5"
            >
                <DataTableFilterPills
                    :filters="activeFilters"
                    @remove="removeFilter"
                    @clear-all="clearFilters"
                />
            </div>
        </section>

        <!-- CARD 2: WORKS TABLE CARD -->
        <section
            aria-label="Tableau des œuvres"
            class="relative overflow-hidden rounded-xl border border-border/75 bg-card shadow-2xs transition-shadow duration-150"
        >
            <!-- Card Header / Table Toolbar -->
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-border/70 bg-card px-4 py-3 sm:px-5"
            >
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex size-7 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <FileText class="size-3.5" />
                    </div>
                    <div class="flex items-center gap-2">
                        <h2
                            class="text-sm font-semibold tracking-tight text-foreground sm:text-base"
                        >
                            {{ t('admin.oeuvres.tableTitle', 'قائمة الأعمال') }}
                        </h2>
                        <span
                            class="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary tabular-nums"
                        >
                            <bdi dir="ltr">{{ oeuvres.total }}</bdi>
                        </span>
                    </div>
                </div>

                <div class="text-xs text-muted-foreground">
                    <span v-if="oeuvres.from && oeuvres.to">
                        <i18n-t keypath="admin.pagination.showing" tag="span">
                            <template #from>
                                <bdi
                                    dir="ltr"
                                    class="font-medium text-foreground"
                                    >{{ oeuvres.from }}</bdi
                                >
                            </template>
                            <template #to>
                                <bdi
                                    dir="ltr"
                                    class="font-medium text-foreground"
                                    >{{ oeuvres.to }}</bdi
                                >
                            </template>
                            <template #total>
                                <bdi
                                    dir="ltr"
                                    class="font-medium text-foreground"
                                    >{{ oeuvres.total }}</bdi
                                >
                            </template>
                        </i18n-t>
                    </span>
                </div>
            </div>

            <!-- Table Viewport with Horizontal Scroll & Sticky Header -->
            <TableScroll>
                <table class="w-full border-collapse text-xs">
                    <thead>
                        <tr
                            class="border-b border-border/70 bg-muted/45 text-xs font-semibold text-muted-foreground"
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
                            <th class="px-4 py-3 text-center font-medium">
                                {{ t('admin.oeuvres.colReady') }}
                            </th>
                            <th class="px-4 py-3 text-end font-medium">
                                {{ t('admin.oeuvres.colActions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/50">
                        <!-- Empty State Row (Colspan 9) -->
                        <tr v-if="oeuvres.data.length === 0">
                            <td
                                :colspan="COLUMN_COUNT"
                                class="px-4 py-16 text-center"
                            >
                                <div
                                    class="mx-auto flex max-w-sm flex-col items-center justify-center"
                                >
                                    <div
                                        class="flex size-12 items-center justify-center rounded-2xl bg-muted/60 text-muted-foreground"
                                    >
                                        <Inbox class="size-6" />
                                    </div>
                                    <p
                                        class="mt-3 text-sm font-semibold text-foreground"
                                    >
                                        {{
                                            hasActiveFilters
                                                ? t(
                                                      'admin.oeuvres.emptyFiltered',
                                                  )
                                                : t('admin.oeuvres.emptyNone')
                                        }}
                                    </p>
                                    <p
                                        v-if="!hasActiveFilters"
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ t('admin.oeuvres.emptyDraftsHint') }}
                                    </p>
                                    <Button
                                        v-else
                                        variant="outline"
                                        size="sm"
                                        class="mt-4 h-8 gap-1.5 text-xs shadow-2xs"
                                        @click="clearFilters"
                                    >
                                        <RotateCcw class="size-3" />
                                        {{ t('admin.oeuvres.resetFilters') }}
                                    </Button>
                                </div>
                            </td>
                        </tr>

                        <!-- Table Data Rows -->
                        <tr
                            v-for="(oeuvre, index) in oeuvres.data"
                            :key="oeuvre.uuid"
                            class="group transition-colors duration-150 hover:bg-muted/35"
                        >
                            <!-- 1. Index -->
                            <td
                                class="w-12 px-3 py-3.5 text-center font-mono text-xs text-muted-foreground/75"
                            >
                                {{ (oeuvres.from ?? 1) + index }}
                            </td>

                            <!-- 2. Work Title -->
                            <td class="px-4 py-3.5">
                                <TooltipProvider :delay-duration="250">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Link
                                                :href="oeuvreShow(oeuvre.uuid)"
                                                class="block max-w-xs truncate font-medium text-foreground transition-colors hover:text-primary md:max-w-sm"
                                            >
                                                <bdi dir="ltr">{{
                                                    oeuvreLabel(
                                                        oeuvre,
                                                        locale,
                                                        t('oeuvres.untitled'),
                                                    )
                                                }}</bdi>
                                            </Link>
                                        </TooltipTrigger>
                                        <TooltipContent side="top">
                                            <p
                                                class="max-w-xs text-xs font-normal"
                                            >
                                                {{
                                                    oeuvreLabel(
                                                        oeuvre,
                                                        locale,
                                                        t('oeuvres.untitled'),
                                                    )
                                                }}
                                            </p>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                                <p
                                    v-if="oeuvre.college_name"
                                    class="mt-0.5 max-w-xs truncate text-[11px] text-muted-foreground/75"
                                >
                                    {{ oeuvre.college_name }}
                                </p>
                            </td>

                            <!-- 3. Author -->
                            <td class="px-4 py-3 text-muted-foreground">
                                <Link
                                    v-if="oeuvre.author"
                                    :href="authorShow(oeuvre.author.uuid)"
                                    class="font-medium text-foreground/85 transition-colors hover:text-primary hover:underline"
                                >
                                    <bdi>{{ oeuvre.author.name }}</bdi>
                                </Link>
                                <span v-else class="text-muted-foreground/60"
                                    >—</span
                                >
                            </td>

                            <!-- 4. Status Badge -->
                            <td class="px-4 py-3">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <DataTableStatusBadge
                                        :status="oeuvre.status"
                                        :label="
                                            t(`oeuvres.status.${oeuvre.status}`)
                                        "
                                    />
                                    <span
                                        v-if="oeuvre.has_blocking_file"
                                        class="inline-flex items-center gap-1 rounded-full border border-destructive/20 bg-destructive/10 px-2 py-0.5 text-[11px] font-medium text-destructive"
                                        :title="
                                            t('admin.oeuvres.hasBlockingFile')
                                        "
                                    >
                                        <AlertTriangle
                                            class="size-3 shrink-0"
                                        />
                                        <span>{{
                                            t('admin.oeuvres.hasBlockingFile')
                                        }}</span>
                                    </span>
                                </div>
                            </td>

                            <!-- 5. Files Count -->
                            <td
                                class="px-4 py-3 font-mono text-muted-foreground"
                            >
                                <bdi dir="ltr">{{ oeuvre.files_count }}</bdi>
                            </td>

                            <!-- 6. Total Size -->
                            <td
                                class="px-4 py-3 font-mono whitespace-nowrap text-muted-foreground"
                            >
                                <bdi dir="ltr">{{
                                    formatBytes(oeuvre.files_size_bytes, locale)
                                }}</bdi>
                            </td>

                            <!-- 7. Submitted At -->
                            <td
                                class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                            >
                                <bdi dir="ltr">{{
                                    oeuvre.submitted_at
                                        ? formatDate(
                                              oeuvre.submitted_at,
                                              locale,
                                          )
                                        : '—'
                                }}</bdi>
                            </td>

                            <!-- 8. Ready Indicator -->
                            <td class="px-4 py-3 text-center">
                                <CheckCircle2
                                    v-if="oeuvre.all_ready"
                                    class="inline-block size-4 text-emerald-600 dark:text-emerald-400"
                                />
                                <span v-else class="text-muted-foreground/60"
                                    >—</span
                                >
                            </td>

                            <!-- 9. Actions Column -->
                            <td class="px-4 py-3 text-end">
                                <div
                                    class="flex items-center justify-end gap-1.5"
                                >
                                    <Button
                                        as-child
                                        variant="outline"
                                        size="sm"
                                    >
                                        <Link
                                            :href="oeuvreShow(oeuvre.uuid)"
                                            data-testid="view"
                                            :aria-label="`${t('admin.oeuvres.view')} : ${oeuvreLabel(oeuvre, locale, t('oeuvres.untitled'))}`"
                                        >
                                            <Eye class="size-4" />
                                            {{ t('admin.oeuvres.view') }}
                                        </Link>
                                    </Button>
                                    <ExamineButton
                                        v-if="
                                            EXAMINABLE.includes(oeuvre.status)
                                        "
                                        :label="
                                            oeuvreLabel(
                                                oeuvre,
                                                locale,
                                                t('oeuvres.untitled'),
                                            )
                                        "
                                        @click="examine(oeuvre.uuid)"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </TableScroll>

            <!-- Table Pagination Footer -->
            <DataTablePagination :paginated="oeuvres" show-page-size />
        </section>

        <ReviewDecisionDialog
            v-model:open="dialogOpen"
            :oeuvre="examining"
            @changed="onDecision"
        />
    </div>
</template>
