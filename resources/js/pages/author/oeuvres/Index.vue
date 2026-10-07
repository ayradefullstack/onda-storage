<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    BookOpen,
    Check,
    Clock,
    Copy,
    ExternalLink,
    FileText,
    Files,
    FolderArchive,
    FolderKanban,
    FolderPlus,
    Grid3X3,
    List,
    Music,
    Plus,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Video,
} from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { oeuvreLabel } from '@/components/oeuvre/label';
import OeuvreDeleteDialog from '@/components/oeuvre/OeuvreDeleteDialog.vue';
import OeuvreSubmitDialog from '@/components/oeuvre/OeuvreSubmitDialog.vue';
import { ActionButton } from '@/components/ui/action';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DataTable,
    DataTableToolbar,
    DataTableSearch,
    DataTableFilterPills,
    DataTableStatusBadge,
    DataTableProgress,
    DataTablePagination,
    DataTableEmpty,
} from '@/components/ui/data-table';
import { create, index, show } from '@/routes/oeuvres';

/** What the row may offer. Mirrors OeuvrePolicy; the server re-checks. */
interface OeuvreAbilities {
    edit: boolean;
    delete: boolean;
    /** Advisory — SubmissionGate decides for real on POST. */
    submit: boolean;
}

/** Required slots satisfied, out of how many. See SlotProgressQuery. */
interface DocumentsProgress {
    satisfied: number;
    total: number;
    /** Unsatisfied required slots that may not apply to this work. */
    conditional: number;
}

interface OeuvreSummary {
    id: number;
    uuid: string;
    title: string | null;
    description?: string | null;
    status: string;
    created_at: string;
    submitted_at: string | null;
    media_files_count: number;
    college_name: string | null;
    documents: DocumentsProgress;
    can: OeuvreAbilities;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    oeuvres: Paginated<OeuvreSummary>;
    filters: { search: string; status: string; sort: string };
    statuses: string[];
    sorts: string[];
    counts: Record<string, number>;
}>();

const { t, locale } = useI18n();

// A new oeuvre has no title until a later step names it — every display
// below goes through its label instead. Searching and sorting happen on
// the server, over `title`, because the label is a client-side derivation
// the database cannot see (see OeuvreController::applySort()).
const labelledOeuvres = computed(() =>
    props.oeuvres.data.map((oeuvre) => ({
        ...oeuvre,
        label: oeuvreLabel(oeuvre, locale.value, t('oeuvres.untitled')),
    })),
);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Works', href: index() }],
    },
});

// --- Controls & filters. Server-driven: the page holds one page of rows,
// so filtering it in the browser would only ever filter what is already
// visible. Each control pushes the query string and Inertia re-renders
// just this page's props.
const searchQuery = ref(props.filters.search);
const selectedStatus = ref(props.filters.status);
const selectedSort = ref(props.filters.sort);
// The table is the default view — this is a registry, and a registry is
// read in rows. The card grid stays available behind the toggle.
const viewMode = ref<'list' | 'grid'>('list');
const copiedUuid = ref<string | null>(null);

const applyFilters = (page?: number) => {
    router.get(
        index().url,
        {
            ...(searchQuery.value.trim() !== ''
                ? { search: searchQuery.value.trim() }
                : {}),
            ...(selectedStatus.value !== ''
                ? { status: selectedStatus.value }
                : {}),
            ...(selectedSort.value !== 'newest'
                ? { sort: selectedSort.value }
                : {}),
            ...(page && page > 1 ? { page } : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['oeuvres', 'filters', 'counts'],
        },
    );
};

// Debounced so a search does not fire a request per keystroke. Filters and
// sort apply immediately — those are single deliberate clicks.
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(searchQuery, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters(), 350);
});

watch([selectedStatus, selectedSort], () => applyFilters());

const setStatus = (status: string) => {
    selectedStatus.value = status;
};

// --- Row actions. `deleting` and `submitting` hold the row a dialog is
// open for; both are confirmed, because both surprise people — see the
// dialog components for what each one has to say.
const deleting = ref<(OeuvreSummary & { label: string }) | null>(null);
const submitting = ref<(OeuvreSummary & { label: string }) | null>(null);

// Copy UUID with feedback
const copyOeuvreUuid = async (uuid: string) => {
    try {
        await navigator.clipboard.writeText(uuid);
        copiedUuid.value = uuid;
        setTimeout(() => {
            if (copiedUuid.value === uuid) {
                copiedUuid.value = null;
            }
        }, 2000);
    } catch {
        // Fallback
    }
};

// Global Hotkeys (⌘N / Ctrl+N for New Work) — opens the create page, the
// same destination as the "new work" buttons.
useEventListener('keydown', (e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'n') {
        e.preventDefault();
        router.visit(create());
    }
});

// Category Heuristics & Icons
const getOeuvreCategoryMeta = (title: string, description?: string | null) => {
    const text = `${title} ${description || ''}`.toLowerCase();

    if (
        text.includes('symphon') ||
        text.includes('musique') ||
        text.includes('music') ||
        text.includes('opus') ||
        text.includes('album') ||
        text.includes('chanson') ||
        text.includes('موسيقى') ||
        text.includes('لحن') ||
        text.includes('سيمفونية') ||
        text.includes('غناء')
    ) {
        return {
            label: 'Musique',
            icon: Music,
            colorClass:
                'text-sky-600 dark:text-sky-400 bg-sky-500/10 border-sky-500/20',
            badgeClass:
                'bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-500/30',
        };
    }

    if (
        text.includes('film') ||
        text.includes('cinema') ||
        text.includes('cinéma') ||
        text.includes('video') ||
        text.includes('vidéo') ||
        text.includes('documentaire') ||
        text.includes('court-métrage') ||
        text.includes('سينما') ||
        text.includes('فيلم') ||
        text.includes('وثائقي')
    ) {
        return {
            label: 'Audiovisuel',
            icon: Video,
            colorClass:
                'text-purple-600 dark:text-purple-400 bg-purple-500/10 border-purple-500/20',
            badgeClass:
                'bg-purple-500/10 text-purple-700 dark:text-purple-300 border-purple-500/30',
        };
    }

    if (
        text.includes('livre') ||
        text.includes('roman') ||
        text.includes('poème') ||
        text.includes('recueil') ||
        text.includes('manuscrit') ||
        text.includes('book') ||
        text.includes('novel') ||
        text.includes('كتاب') ||
        text.includes('رواية') ||
        text.includes('قصيدة') ||
        text.includes('مخطوط')
    ) {
        return {
            label: 'Littérature',
            icon: BookOpen,
            colorClass:
                'text-amber-600 dark:text-amber-400 bg-amber-500/10 border-amber-500/20',
            badgeClass:
                'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/30',
        };
    }

    return {
        label: 'Création',
        icon: FolderArchive,
        colorClass:
            'text-onda-blue-600 dark:text-onda-blue-400 bg-onda-blue-500/10 border-onda-blue-500/20',
        badgeClass:
            'bg-onda-blue-500/10 text-onda-blue-700 dark:text-onda-blue-300 border-onda-blue-500/30',
    };
};

// Status Styling
const getStatusMeta = (status: string) => {
    switch (status) {
        case 'registered':
        case 'approved':
            return {
                label: t('oeuvres.status.registered'),
                class: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30',
                dotClass: 'bg-emerald-500',
                pulse: true,
                icon: ShieldCheck,
            };
        // `submitted` and `under_review` are DIFFERENT things to an author
        // and must not share a label: the first means nobody has picked it
        // up yet, the second means an officer is reading it right now.
        // They were collapsed here before the status machine existed.
        case 'submitted':
        case 'pending':
            return {
                label: t('oeuvres.status.submitted'),
                class: 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/30',
                dotClass: 'bg-amber-500',
                pulse: false,
                icon: Clock,
            };
        case 'under_review':
            return {
                label: t('oeuvres.status.under_review'),
                class: 'bg-sky-500/10 text-sky-700 dark:text-sky-300 border-sky-500/30',
                dotClass: 'bg-sky-500',
                pulse: true,
                icon: Clock,
            };
        case 'rejected':
            return {
                label: t('oeuvres.status.rejected'),
                class: 'bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-500/30',
                dotClass: 'bg-rose-500',
                pulse: false,
                icon: ShieldAlert,
            };
        case 'draft':
        default:
            return {
                label: t('oeuvres.status.draft'),
                class: 'bg-muted text-muted-foreground border-border/80',
                dotClass: 'bg-muted-foreground/60',
                pulse: false,
                icon: FileText,
            };
    }
};

// KPI Metrics — counted on the server over the author's whole shelf, not
// over the current page: a tab reading "(3)" must not change when you turn
// the page.
const totalOeuvresCount = computed(() => props.counts.all ?? 0);
const registeredCount = computed(() => props.counts.registered ?? 0);
const underReviewCount = computed(
    () => (props.counts.submitted ?? 0) + (props.counts.under_review ?? 0),
);
const draftCount = computed(() => props.counts.draft ?? 0);
const rejectedCount = computed(() => props.counts.rejected ?? 0);

// Files on this page only, and labelled as such — a page-wide total would
// need another aggregate for a figure nobody acts on.
const totalFilesCount = computed(() =>
    props.oeuvres.data.reduce((acc, w) => acc + (w.media_files_count || 0), 0),
);

// Date Formatter
const formatDate = (dateString: string) => {
    try {
        const d = new Date(dateString);

        return new Intl.DateTimeFormat(
            locale.value === 'ar'
                ? 'ar-DZ'
                : locale.value === 'fr'
                  ? 'fr-FR'
                  : 'en-US',
            {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
            },
        ).format(d);
    } catch {
        return dateString;
    }
};

// The rows the server sent, in the order it sent them. There is
// deliberately no client-side filter or sort left: two sources of truth
// for "which rows" is how a table starts disagreeing with its own
// pagination footer.
const visibleOeuvres = labelledOeuvres;

const hasFilters = computed(
    () =>
        searchQuery.value.trim() !== '' ||
        selectedStatus.value !== '' ||
        selectedSort.value !== 'newest',
);

const activeFilters = computed(() => {
    const pills: { key: string; label: string; value: string }[] = [];

    if (searchQuery.value && searchQuery.value.trim() !== '') {
        pills.push({
            key: 'search',
            label: t('oeuvres.index.searchPlaceholder') || 'Recherche',
            value: `"${searchQuery.value.trim()}"`,
        });
    }

    if (selectedStatus.value !== '') {
        pills.push({
            key: 'status',
            label: t('oeuvres.table.colStatus') || 'Statut',
            value: getStatusMeta(selectedStatus.value).label,
        });
    }

    return pills;
});

const removeFilter = (key: string) => {
    if (key === 'search') {
        searchQuery.value = '';
    } else if (key === 'status') {
        selectedStatus.value = '';
    }
};

const resetFilters = () => {
    searchQuery.value = '';
    selectedStatus.value = '';
    selectedSort.value = 'newest';
};
</script>

<template>
    <Head :title="t('oeuvres.index.title')" />

    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- 1. Top Sovereign Hero Banner -->
        <div
            class="relative overflow-hidden rounded-3xl border border-onda-blue-500/20 bg-gradient-to-br from-onda-blue-50/70 via-card to-card p-5 shadow-xs sm:p-8 dark:border-border/80 dark:from-onda-blue-950/30 dark:via-card dark:to-card"
        >
            <!-- Decorative Ambient Glows -->
            <div
                class="pointer-events-none absolute -end-20 -top-20 size-80 rounded-full bg-gradient-to-br from-onda-blue-500/15 via-onda-teal-500/15 to-transparent blur-3xl dark:from-onda-blue-600/25 dark:via-onda-teal-600/20"
            />
            <div
                class="pointer-events-none absolute -start-12 -bottom-12 size-64 rounded-full bg-onda-teal-500/10 blur-2xl dark:bg-onda-teal-500/15"
            />

            <div
                class="relative z-10 flex flex-col justify-between gap-6 lg:flex-row lg:items-center"
            >
                <div class="max-w-2xl space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            variant="secondary"
                            class="gap-1.5 rounded-full border-onda-blue-500/20 bg-onda-blue-600/10 px-3 py-1 text-xs font-semibold text-onda-blue-700 shadow-xs dark:bg-onda-blue-500/20 dark:text-onda-blue-300"
                        >
                            <Shield
                                class="size-3.5 text-onda-blue-600 dark:text-onda-blue-400"
                            />
                            <span>{{ t('oeuvres.index.title') }}</span>
                        </Badge>

                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-medium text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400"
                        >
                            <span
                                class="size-1.5 animate-pulse rounded-full bg-emerald-500"
                            />
                            {{
                                t('oeuvres.index.showingCount', {
                                    count: totalOeuvresCount,
                                })
                            }}
                        </span>
                    </div>

                    <div class="space-y-1">
                        <h1
                            class="text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl lg:text-4xl"
                        >
                            {{ t('oeuvres.index.title') }}
                        </h1>
                        <p
                            class="text-sm leading-relaxed text-muted-foreground sm:text-base"
                        >
                            {{ t('oeuvres.index.subtitle') }}
                        </p>
                    </div>
                </div>

                <!-- Action Button Group -->
                <div class="flex shrink-0 flex-wrap items-center gap-3">
                    <Button
                        as-child
                        class="h-11 cursor-pointer gap-2 rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 px-5 text-sm font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all duration-200 hover:-translate-y-0.5 hover:from-onda-blue-700 hover:to-onda-blue-800 hover:shadow-onda-blue-600/40 active:translate-y-0 dark:from-onda-blue-500 dark:to-onda-blue-600 dark:text-gray-950"
                    >
                        <Link :href="create()">
                            <FolderPlus class="size-4.5" />
                            <span>{{ t('oeuvres.index.newOeuvre') }}</span>
                            <kbd
                                class="hidden rounded-md bg-white/20 px-1.5 py-0.5 font-mono text-[10px] font-bold text-white uppercase sm:inline-block dark:bg-black/20 dark:text-gray-950"
                                >⌘N</kbd
                            >
                        </Link>
                    </Button>
                </div>
            </div>
        </div>

        <!-- 2. KPI Metrics Ribbon -->
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <!-- Total Works -->
            <Card
                class="group relative overflow-hidden border-border/80 bg-card/70 backdrop-blur-xs transition-all duration-300 hover:border-onda-blue-500/40 hover:shadow-xs"
            >
                <CardContent class="p-4 sm:p-5">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-muted-foreground">
                            {{ t('oeuvres.index.statTotal') }}
                        </span>
                        <div
                            class="flex size-8 items-center justify-center rounded-lg bg-onda-blue-500/10 text-onda-blue-600 transition-transform group-hover:scale-110 dark:text-onda-blue-400"
                        >
                            <FolderKanban class="size-4.5" />
                        </div>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span
                            class="text-2xl font-black tracking-tight text-foreground sm:text-3xl"
                        >
                            {{ totalOeuvresCount }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{
                                t('oeuvres.index.fileCount', {
                                    count: totalOeuvresCount,
                                })
                            }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <!-- Registered & Protected -->
            <Card
                class="group relative overflow-hidden border-border/80 bg-card/70 backdrop-blur-xs transition-all duration-300 hover:border-emerald-500/40 hover:shadow-xs"
            >
                <CardContent class="p-4 sm:p-5">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-muted-foreground">
                            {{ t('oeuvres.index.statRegistered') }}
                        </span>
                        <div
                            class="flex size-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 transition-transform group-hover:scale-110 dark:text-emerald-400"
                        >
                            <ShieldCheck class="size-4.5" />
                        </div>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span
                            class="text-2xl font-black tracking-tight text-emerald-600 sm:text-3xl dark:text-emerald-400"
                        >
                            {{ registeredCount }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            / {{ totalOeuvresCount }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <!-- In Review / Under Process -->
            <Card
                class="group relative overflow-hidden border-border/80 bg-card/70 backdrop-blur-xs transition-all duration-300 hover:border-amber-500/40 hover:shadow-xs"
            >
                <CardContent class="p-4 sm:p-5">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-muted-foreground">
                            {{ t('oeuvres.index.statUnderReview') }}
                        </span>
                        <div
                            class="flex size-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 transition-transform group-hover:scale-110 dark:text-amber-400"
                        >
                            <Clock class="size-4.5" />
                        </div>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span
                            class="text-2xl font-black tracking-tight text-amber-600 sm:text-3xl dark:text-amber-400"
                        >
                            {{ underReviewCount }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{
                                draftCount > 0
                                    ? `(${draftCount} ${t('oeuvres.index.filterDraft')})`
                                    : ''
                            }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <!-- Total Digital Files -->
            <Card
                class="group relative overflow-hidden border-border/80 bg-card/70 backdrop-blur-xs transition-all duration-300 hover:border-onda-teal-500/40 hover:shadow-xs"
            >
                <CardContent class="p-4 sm:p-5">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-muted-foreground">
                            {{ t('oeuvres.index.statFiles') }}
                        </span>
                        <div
                            class="flex size-8 items-center justify-center rounded-lg bg-onda-teal-500/10 text-onda-teal-600 transition-transform group-hover:scale-110 dark:text-onda-teal-400"
                        >
                            <Files class="size-4.5" />
                        </div>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <span
                            class="text-2xl font-black tracking-tight text-onda-teal-600 sm:text-3xl dark:text-onda-teal-400"
                        >
                            {{ totalFilesCount }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ t('oeuvres.index.statFiles') }}
                        </span>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- 3. Zero Works State (No Works at all in account) -->
        <div
            v-if="props.oeuvres.total === 0 && !hasFilters"
            class="relative overflow-hidden rounded-3xl border border-dashed border-border/90 bg-card/60 p-8 text-center backdrop-blur-xs sm:p-14"
        >
            <div
                class="pointer-events-none absolute inset-0 bg-radial from-onda-blue-500/5 via-transparent to-transparent"
            />
            <div class="relative z-10 mx-auto max-w-md space-y-4">
                <div
                    class="mx-auto flex size-16 items-center justify-center rounded-2xl border border-onda-blue-500/20 bg-gradient-to-br from-onda-blue-500/10 to-onda-teal-500/10 text-onda-blue-600 shadow-md dark:text-onda-blue-400"
                >
                    <FolderPlus class="size-8" />
                </div>

                <div class="space-y-1.5">
                    <h3
                        class="text-lg font-bold tracking-tight text-foreground sm:text-xl"
                    >
                        {{ t('oeuvres.index.empty') }}
                    </h3>
                    <p
                        class="text-xs leading-relaxed text-muted-foreground sm:text-sm"
                    >
                        {{ t('oeuvres.index.emptyDescription') }}
                    </p>
                </div>

                <div class="pt-2">
                    <Button
                        as-child
                        class="h-11 cursor-pointer gap-2 rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 px-6 font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all duration-200 hover:-translate-y-0.5 hover:from-onda-blue-700 hover:to-onda-blue-800 hover:shadow-onda-blue-600/40"
                    >
                        <Link :href="create()">
                            <Plus class="size-4" />
                            <span>{{ t('oeuvres.index.emptyAction') }}</span>
                        </Link>
                    </Button>
                </div>
            </div>
        </div>

        <!-- 4. Sovereign Data Table & Views -->
        <DataTable v-else>
            <template #toolbar>
                <DataTableToolbar
                    :title="t('oeuvres.index.title')"
                    :count="props.oeuvres.total"
                >
                    <template #search>
                        <DataTableSearch
                            v-model="searchQuery"
                            :placeholder="t('oeuvres.index.searchPlaceholder')"
                            class="w-full sm:w-64"
                        />
                    </template>

                    <template #filters>
                        <!-- Sort Dropdown -->
                        <div class="relative flex items-center">
                            <ArrowUpDown
                                class="pointer-events-none absolute start-3 size-3.5 text-muted-foreground"
                            />
                            <select
                                v-model="selectedSort"
                                class="input-premium h-9 cursor-pointer appearance-none rounded-lg bg-background ps-8 pe-8 text-xs font-medium text-foreground focus:outline-none"
                            >
                                <option value="newest">
                                    {{ t('oeuvres.index.sortNewest') }}
                                </option>
                                <option value="oldest">
                                    {{ t('oeuvres.index.sortOldest') }}
                                </option>
                                <option value="title">
                                    {{ t('oeuvres.index.sortTitle') }}
                                </option>
                                <option value="files">
                                    {{ t('oeuvres.index.sortFiles') }}
                                </option>
                            </select>
                        </div>
                    </template>

                    <template #actions>
                        <!-- View Mode Switcher -->
                        <div
                            class="flex items-center rounded-lg border border-border/60 bg-muted/40 p-0.5"
                        >
                            <button
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-md p-1.5 transition-all',
                                    viewMode === 'list'
                                        ? 'bg-background text-onda-blue-600 shadow-xs dark:text-onda-blue-400'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                :title="t('oeuvres.index.list')"
                                @click="viewMode = 'list'"
                            >
                                <List class="size-4" />
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-md p-1.5 transition-all',
                                    viewMode === 'grid'
                                        ? 'bg-background text-onda-blue-600 shadow-xs dark:text-onda-blue-400'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                :title="t('oeuvres.index.grid')"
                                @click="viewMode = 'grid'"
                            >
                                <Grid3X3 class="size-4" />
                            </button>
                        </div>
                    </template>

                    <template #tabs>
                        <div
                            class="flex items-center gap-1 overflow-x-auto rounded-lg border border-border/60 bg-muted/40 p-1 text-xs"
                        >
                            <button
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-md px-3 py-1.5 font-medium whitespace-nowrap transition-all',
                                    selectedStatus === ''
                                        ? 'bg-background font-semibold text-foreground shadow-xs'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="setStatus('')"
                            >
                                {{ t('oeuvres.index.filterAll') }}
                                <span class="ms-1 text-[10px] opacity-70"
                                    >({{ totalOeuvresCount }})</span
                                >
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-md px-3 py-1.5 font-medium whitespace-nowrap transition-all',
                                    selectedStatus === 'draft'
                                        ? 'bg-background font-semibold text-foreground shadow-xs'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="setStatus('draft')"
                            >
                                {{ t('oeuvres.index.filterDraft') }}
                                <span class="ms-1 text-[10px] opacity-70"
                                    >({{ draftCount }})</span
                                >
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-md px-3 py-1.5 font-medium whitespace-nowrap transition-all',
                                    selectedStatus === 'submitted'
                                        ? 'bg-background font-semibold text-amber-600 shadow-xs dark:text-amber-400'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="setStatus('submitted')"
                            >
                                {{ t('oeuvres.status.submitted') }}
                                <span class="ms-1 text-[10px] opacity-70"
                                    >({{ counts.submitted ?? 0 }})</span
                                >
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-md px-3 py-1.5 font-medium whitespace-nowrap transition-all',
                                    selectedStatus === 'under_review'
                                        ? 'bg-background font-semibold text-sky-600 shadow-xs dark:text-sky-400'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="setStatus('under_review')"
                            >
                                {{ t('oeuvres.index.filterUnderReview') }}
                                <span class="ms-1 text-[10px] opacity-70"
                                    >({{ counts.under_review ?? 0 }})</span
                                >
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-md px-3 py-1.5 font-medium whitespace-nowrap transition-all',
                                    selectedStatus === 'registered'
                                        ? 'bg-background font-semibold text-emerald-600 shadow-xs dark:text-emerald-400'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="setStatus('registered')"
                            >
                                {{ t('oeuvres.index.filterRegistered') }}
                                <span class="ms-1 text-[10px] opacity-70"
                                    >({{ registeredCount }})</span
                                >
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'cursor-pointer rounded-md px-3 py-1.5 font-medium whitespace-nowrap transition-all',
                                    selectedStatus === 'rejected'
                                        ? 'bg-background font-semibold text-rose-600 shadow-xs dark:text-rose-400'
                                        : rejectedCount > 0
                                          ? 'text-rose-600 hover:text-rose-700 dark:text-rose-400'
                                          : 'text-muted-foreground hover:text-foreground',
                                ]"
                                @click="setStatus('rejected')"
                            >
                                {{ t('oeuvres.status.rejected') }}
                                <span class="ms-1 text-[10px] opacity-70"
                                    >({{ rejectedCount }})</span
                                >
                            </button>
                        </div>
                    </template>

                    <template #filter-pills>
                        <DataTableFilterPills
                            :filters="activeFilters"
                            @remove="removeFilter"
                            @clear-all="resetFilters"
                        />
                    </template>
                </DataTableToolbar>
            </template>

            <!-- A. No Search / Filter Results State -->
            <DataTableEmpty
                v-if="visibleOeuvres.length === 0"
                :title="t('oeuvres.index.noSearchResults')"
                :description="t('oeuvres.index.searchPlaceholder')"
                :has-active-filters="hasFilters"
                @clear-filters="resetFilters"
            />

            <!-- B. Grid View Mode -->
            <div
                v-else-if="viewMode === 'grid'"
                class="grid grid-cols-1 gap-4 p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-3"
            >
                <div
                    v-for="oeuvre in visibleOeuvres"
                    :key="oeuvre.uuid"
                    class="group relative flex flex-col justify-between overflow-hidden rounded-2xl border border-border/80 bg-card p-5 shadow-xs transition-all duration-300 hover:-translate-y-1 hover:border-onda-blue-500/40 hover:shadow-xl hover:shadow-onda-blue-600/5 dark:bg-card/90 dark:hover:border-onda-blue-400/40"
                >
                    <!-- Top Card Bar: Category & Status Badge -->
                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <!-- Category Badge -->
                            <div class="flex items-center gap-1.5">
                                <div
                                    :class="[
                                        'flex size-7 items-center justify-center rounded-lg border',
                                        getOeuvreCategoryMeta(
                                            oeuvre.label,
                                            oeuvre.description,
                                        ).colorClass,
                                    ]"
                                >
                                    <component
                                        :is="
                                            getOeuvreCategoryMeta(
                                                oeuvre.label,
                                                oeuvre.description,
                                            ).icon
                                        "
                                        class="size-3.5"
                                    />
                                </div>
                                <span
                                    :class="[
                                        'rounded-md border px-2 py-0.5 text-[11px] font-semibold',
                                        getOeuvreCategoryMeta(
                                            oeuvre.label,
                                            oeuvre.description,
                                        ).badgeClass,
                                    ]"
                                >
                                    {{
                                        getOeuvreCategoryMeta(
                                            oeuvre.label,
                                            oeuvre.description,
                                        ).label
                                    }}
                                </span>
                            </div>

                            <!-- Status Badge -->
                            <DataTableStatusBadge
                                :status="oeuvre.status"
                                :label="getStatusMeta(oeuvre.status).label"
                                :pulse="getStatusMeta(oeuvre.status).pulse"
                            />
                        </div>

                        <!-- Work Title & Description -->
                        <div class="mt-4 space-y-1.5">
                            <Link
                                :href="show(oeuvre.uuid)"
                                class="block group-hover:text-onda-blue-600 dark:group-hover:text-onda-blue-400"
                            >
                                <h2
                                    class="line-clamp-1 text-base font-bold tracking-tight text-foreground transition-colors"
                                    :title="oeuvre.label"
                                >
                                    {{ oeuvre.label }}
                                </h2>
                            </Link>
                            <p
                                class="line-clamp-2 text-xs leading-relaxed text-muted-foreground"
                                :title="oeuvre.description || ''"
                            >
                                {{
                                    oeuvre.description ||
                                    t('oeuvres.index.noDescription')
                                }}
                            </p>
                        </div>
                    </div>

                    <!-- Meta Pills & Footer Actions -->
                    <div
                        class="mt-5 space-y-3.5 border-t border-border/60 pt-4"
                    >
                        <!-- Files & Date Ribbon -->
                        <div
                            class="flex items-center justify-between text-xs text-muted-foreground"
                        >
                            <div
                                class="flex items-center gap-1.5 rounded-md bg-muted/60 px-2 py-1 font-medium text-foreground/90"
                            >
                                <Files
                                    class="size-3.5 text-onda-blue-600 dark:text-onda-blue-400"
                                />
                                <span>{{
                                    t('oeuvres.index.fileCount', {
                                        count: oeuvre.media_files_count || 0,
                                    })
                                }}</span>
                            </div>

                            <span
                                class="font-mono text-[11px] text-muted-foreground"
                            >
                                {{ formatDate(oeuvre.created_at) }}
                            </span>
                        </div>

                        <!-- Bottom Action Buttons -->
                        <div class="flex items-center justify-between gap-2">
                            <!-- Quick Copy UUID Button -->
                            <button
                                type="button"
                                class="flex cursor-pointer items-center gap-1 rounded-lg border border-border/80 bg-muted/40 px-2 py-1.5 font-mono text-[11px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                :title="oeuvre.uuid"
                                @click="copyOeuvreUuid(oeuvre.uuid)"
                            >
                                <component
                                    :is="
                                        copiedUuid === oeuvre.uuid
                                            ? Check
                                            : Copy
                                    "
                                    :class="[
                                        'size-3 shrink-0',
                                        copiedUuid === oeuvre.uuid
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-muted-foreground',
                                    ]"
                                />
                                <span
                                    class="max-w-[75px] truncate sm:max-w-[90px]"
                                >
                                    {{
                                        copiedUuid === oeuvre.uuid
                                            ? t('oeuvres.index.uuidCopied')
                                            : oeuvre.uuid.slice(0, 8) + '...'
                                    }}
                                </span>
                            </button>

                            <!-- Enter Work Details / Upload Hub -->
                            <Button
                                as-child
                                size="sm"
                                class="h-8.5 cursor-pointer gap-1.5 rounded-xl bg-onda-blue-600/10 px-3 text-xs font-semibold text-onda-blue-700 transition-all hover:bg-onda-blue-600 hover:text-white dark:bg-onda-blue-500/20 dark:text-onda-blue-300 dark:hover:bg-onda-blue-500 dark:hover:text-gray-950"
                            >
                                <Link :href="show(oeuvre.uuid)">
                                    <span>{{
                                        t('oeuvres.index.manageOeuvre')
                                    }}</span>
                                    <ExternalLink
                                        class="size-3 rtl:rotate-180"
                                    />
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- C. Enterprise Registry Table (Default View) -->
            <table
                v-else-if="viewMode === 'list'"
                class="w-full border-collapse text-start text-xs"
            >
                <thead>
                    <tr
                        class="border-b border-border/70 bg-muted/40 font-semibold text-muted-foreground"
                    >
                        <th class="w-12 px-3 py-3 text-center font-medium">
                            #
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            {{ t('oeuvres.table.colOeuvre') }}
                        </th>
                        <th
                            class="hidden px-4 py-3 text-start font-medium lg:table-cell"
                        >
                            {{ t('oeuvres.table.colCollege') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            {{ t('oeuvres.table.colDocuments') }}
                        </th>
                        <th class="px-4 py-3 text-start font-medium">
                            {{ t('oeuvres.table.colStatus') }}
                        </th>
                        <th
                            class="hidden px-4 py-3 text-start font-medium sm:table-cell"
                        >
                            {{ t('oeuvres.table.colCreated') }}
                        </th>
                        <th class="px-4 py-3 text-end font-medium">
                            {{ t('oeuvres.table.colActions') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    <tr
                        v-for="(oeuvre, index) in visibleOeuvres"
                        :key="oeuvre.uuid"
                        :class="[
                            'group transition-colors duration-150',
                            oeuvre.status === 'rejected'
                                ? 'bg-rose-500/[0.04] hover:bg-rose-500/[0.08] dark:bg-rose-500/[0.07] dark:hover:bg-rose-500/[0.11]'
                                : 'hover:bg-muted/40',
                        ]"
                    >
                        <td
                            class="w-12 px-3 py-3.5 text-center text-xs font-medium text-muted-foreground"
                        >
                            {{ (props.oeuvres.from ?? 1) + index }}
                        </td>

                        <!-- Œuvre display label & UUID -->
                        <td
                            :class="[
                                'px-4 py-3.5',
                                oeuvre.status === 'rejected'
                                    ? 'border-s-[3px] border-s-rose-500'
                                    : 'border-s-[3px] border-s-transparent',
                            ]"
                        >
                            <Link
                                :href="show(oeuvre.uuid)"
                                class="flex items-center gap-3"
                            >
                                <div
                                    :class="[
                                        'flex size-8 shrink-0 items-center justify-center rounded-lg border',
                                        getOeuvreCategoryMeta(
                                            oeuvre.label,
                                            oeuvre.description,
                                        ).colorClass,
                                    ]"
                                >
                                    <component
                                        :is="
                                            getOeuvreCategoryMeta(
                                                oeuvre.label,
                                                oeuvre.description,
                                            ).icon
                                        "
                                        class="size-4"
                                    />
                                </div>
                                <div class="min-w-0 space-y-0.5">
                                    <bdi
                                        class="block max-w-xs truncate font-bold text-foreground transition-colors group-hover:text-onda-blue-600 sm:max-w-sm md:max-w-md dark:group-hover:text-onda-blue-400"
                                    >
                                        {{ oeuvre.label }}
                                    </bdi>
                                    <button
                                        type="button"
                                        class="flex cursor-pointer items-center gap-1.5 font-mono text-[10px] text-muted-foreground transition-colors hover:text-foreground"
                                        :title="oeuvre.uuid"
                                        @click.prevent.stop="
                                            copyOeuvreUuid(oeuvre.uuid)
                                        "
                                    >
                                        <bdi
                                            >{{ oeuvre.uuid.slice(0, 8) }}…</bdi
                                        >
                                        <component
                                            :is="
                                                copiedUuid === oeuvre.uuid
                                                    ? Check
                                                    : Copy
                                            "
                                            :class="[
                                                'size-2.5 shrink-0',
                                                copiedUuid === oeuvre.uuid
                                                    ? 'text-emerald-600 dark:text-emerald-400'
                                                    : '',
                                            ]"
                                        />
                                    </button>
                                </div>
                            </Link>
                        </td>

                        <!-- Collège -->
                        <td
                            class="hidden max-w-[14rem] px-4 py-3.5 lg:table-cell"
                        >
                            <bdi
                                v-if="oeuvre.college_name"
                                class="block truncate text-muted-foreground"
                                >{{ oeuvre.college_name }}</bdi
                            >
                            <span v-else class="text-muted-foreground/60"
                                >—</span
                            >
                        </td>

                        <!-- Documents progress -->
                        <td class="px-4 py-3.5 whitespace-nowrap">
                            <div
                                v-if="oeuvre.documents.total > 0"
                                class="flex min-w-[130px] flex-col gap-1"
                            >
                                <DataTableProgress
                                    :value="oeuvre.documents.satisfied"
                                    :max="oeuvre.documents.total"
                                    :label="`${oeuvre.documents.satisfied}/${oeuvre.documents.total}`"
                                    size="sm"
                                    :tone="
                                        oeuvre.documents.satisfied >=
                                        oeuvre.documents.total
                                            ? 'emerald'
                                            : 'blue'
                                    "
                                />
                                <p
                                    v-if="oeuvre.documents.conditional > 0"
                                    class="text-[10px] text-muted-foreground"
                                >
                                    {{
                                        t('oeuvres.table.mayNotApply', {
                                            count: oeuvre.documents.conditional,
                                        })
                                    }}
                                </p>
                            </div>
                            <span
                                v-else
                                class="inline-flex items-center gap-1 rounded-md bg-muted/60 px-2 py-0.5 font-mono text-muted-foreground"
                            >
                                <Files class="size-3 opacity-70" />
                                <bdi>{{ oeuvre.media_files_count }}</bdi>
                            </span>
                        </td>

                        <!-- Status Badge -->
                        <td class="px-4 py-3.5 whitespace-nowrap">
                            <DataTableStatusBadge
                                :status="oeuvre.status"
                                :label="getStatusMeta(oeuvre.status).label"
                                :pulse="getStatusMeta(oeuvre.status).pulse"
                            />
                        </td>

                        <!-- Created date -->
                        <td
                            class="hidden px-4 py-3.5 whitespace-nowrap text-muted-foreground sm:table-cell"
                        >
                            <bdi>{{ formatDate(oeuvre.created_at) }}</bdi>
                        </td>

                        <!-- Actions -->
                        <td class="px-4 py-3.5 text-end whitespace-nowrap">
                            <div class="flex items-center justify-end gap-1">
                                <ActionButton
                                    action="view"
                                    size="sm"
                                    :label="t('oeuvres.table.view')"
                                    :href="show(oeuvre.uuid).url"
                                />
                                <ActionButton
                                    v-if="oeuvre.can.edit"
                                    action="edit"
                                    size="sm"
                                    :label="t('oeuvres.table.edit')"
                                    :href="show(oeuvre.uuid).url"
                                />
                                <ActionButton
                                    v-if="oeuvre.can.submit"
                                    action="submit"
                                    size="sm"
                                    :label="t('oeuvres.table.submit')"
                                    @click="submitting = oeuvre"
                                />
                                <ActionButton
                                    v-if="oeuvre.can.delete"
                                    action="delete"
                                    size="sm"
                                    :label="t('oeuvres.table.delete')"
                                    @click="deleting = oeuvre"
                                />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <template #pagination>
                <DataTablePagination
                    :paginated="props.oeuvres"
                    :show-page-size="true"
                />
            </template>
        </DataTable>

        <OeuvreDeleteDialog :oeuvre="deleting" @close="deleting = null" />
        <OeuvreSubmitDialog :oeuvre="submitting" @close="submitting = null" />
    </div>
</template>
