<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
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
    RotateCcw,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Video,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index, show } from '@/routes/works';

interface WorkSummary {
    id: number;
    uuid: string;
    title: string;
    description?: string | null;
    status: string;
    created_at: string;
    media_files_count: number;
}

const props = defineProps<{
    works: WorkSummary[];
}>();

const { t, locale } = useI18n();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Works', href: index() }],
    },
});

// Controls & Filters State
const searchQuery = ref('');
const selectedStatus = ref<'all' | 'registered' | 'under_review' | 'draft'>(
    'all',
);
const selectedSort = ref<'newest' | 'oldest' | 'title' | 'files'>('newest');
const viewMode = ref<'grid' | 'list'>('grid');
const copiedUuid = ref<string | null>(null);
const isQuickCreateOpen = ref(false);

// Quick-Create Form via Inertia
const form = useForm({
    title: '',
    description: '',
});

const submitQuickCreate = () => {
    form.post('/author/works', {
        preserveScroll: true,
        onSuccess: () => {
            isQuickCreateOpen.value = false;
            form.reset();
        },
    });
};

// Copy UUID with feedback
const copyWorkUuid = async (uuid: string) => {
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

// Global Hotkeys (⌘N / Ctrl+N for New Work)
useEventListener('keydown', (e: KeyboardEvent) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'n') {
        e.preventDefault();
        isQuickCreateOpen.value = true;
    }
});

// Category Heuristics & Icons
const getWorkCategoryMeta = (title: string, description?: string | null) => {
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
                label: t('works.status.registered'),
                class: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30',
                dotClass: 'bg-emerald-500',
                pulse: true,
                icon: ShieldCheck,
            };
        case 'under_review':
        case 'submitted':
        case 'pending':
            return {
                label: t('works.status.under_review'),
                class: 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/30',
                dotClass: 'bg-amber-500',
                pulse: true,
                icon: Clock,
            };
        case 'rejected':
            return {
                label: t('works.status.rejected'),
                class: 'bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-500/30',
                dotClass: 'bg-rose-500',
                pulse: false,
                icon: ShieldAlert,
            };
        case 'draft':
        default:
            return {
                label: t('works.status.draft'),
                class: 'bg-muted text-muted-foreground border-border/80',
                dotClass: 'bg-muted-foreground/60',
                pulse: false,
                icon: FileText,
            };
    }
};

// KPI Metrics
const totalWorksCount = computed(() => props.works.length);

const registeredCount = computed(
    () =>
        props.works.filter((w) => ['registered', 'approved'].includes(w.status))
            .length,
);

const underReviewCount = computed(
    () =>
        props.works.filter((w) =>
            ['under_review', 'submitted', 'pending'].includes(w.status),
        ).length,
);

const draftCount = computed(
    () => props.works.filter((w) => w.status === 'draft').length,
);

const totalFilesCount = computed(() =>
    props.works.reduce((acc, w) => acc + (w.media_files_count || 0), 0),
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

// Filtered & Sorted Works
const filteredWorks = computed(() => {
    let result = props.works.filter((work) => {
        // Search Filter
        const query = searchQuery.value.trim().toLowerCase();
        const matchesSearch =
            query === '' ||
            work.title.toLowerCase().includes(query) ||
            (work.description &&
                work.description.toLowerCase().includes(query)) ||
            work.uuid.toLowerCase().includes(query);

        // Status Filter
        let matchesStatus = true;
        if (selectedStatus.value === 'registered') {
            matchesStatus = ['registered', 'approved'].includes(work.status);
        } else if (selectedStatus.value === 'under_review') {
            matchesStatus = ['under_review', 'submitted', 'pending'].includes(
                work.status,
            );
        } else if (selectedStatus.value === 'draft') {
            matchesStatus = work.status === 'draft';
        }

        return matchesSearch && matchesStatus;
    });

    // Sorting
    return result.sort((a, b) => {
        if (selectedSort.value === 'newest') {
            return (
                new Date(b.created_at).getTime() -
                new Date(a.created_at).getTime()
            );
        }
        if (selectedSort.value === 'oldest') {
            return (
                new Date(a.created_at).getTime() -
                new Date(b.created_at).getTime()
            );
        }
        if (selectedSort.value === 'title') {
            return a.title.localeCompare(b.title);
        }
        if (selectedSort.value === 'files') {
            return (b.media_files_count || 0) - (a.media_files_count || 0);
        }

        return 0;
    });
});

const resetFilters = () => {
    searchQuery.value = '';
    selectedStatus.value = 'all';
    selectedSort.value = 'newest';
};
</script>

<template>
    <Head :title="t('works.index.title')" />

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
                            <span>{{ t('works.index.title') }}</span>
                        </Badge>

                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-xs font-medium text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400"
                        >
                            <span
                                class="size-1.5 animate-pulse rounded-full bg-emerald-500"
                            />
                            {{
                                t('works.index.showingCount', {
                                    count: totalWorksCount,
                                })
                            }}
                        </span>
                    </div>

                    <div class="space-y-1">
                        <h1
                            class="text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl lg:text-4xl"
                        >
                            {{ t('works.index.title') }}
                        </h1>
                        <p
                            class="text-sm leading-relaxed text-muted-foreground sm:text-base"
                        >
                            {{ t('works.index.subtitle') }}
                        </p>
                    </div>
                </div>

                <!-- Action Button Group -->
                <div class="flex shrink-0 flex-wrap items-center gap-3">
                    <Button
                        class="h-11 cursor-pointer gap-2 rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 px-5 text-sm font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all duration-200 hover:-translate-y-0.5 hover:from-onda-blue-700 hover:to-onda-blue-800 hover:shadow-onda-blue-600/40 active:translate-y-0 dark:from-onda-blue-500 dark:to-onda-blue-600 dark:text-gray-950"
                        @click="isQuickCreateOpen = true"
                    >
                        <FolderPlus class="size-4.5" />
                        <span>{{ t('works.index.newWork') }}</span>
                        <kbd
                            class="hidden rounded-md bg-white/20 px-1.5 py-0.5 font-mono text-[10px] font-bold text-white uppercase sm:inline-block dark:bg-black/20 dark:text-gray-950"
                            >⌘N</kbd
                        >
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
                            {{ t('works.index.statTotal') }}
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
                            {{ totalWorksCount }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{
                                t('works.index.fileCount', {
                                    count: totalWorksCount,
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
                            {{ t('works.index.statRegistered') }}
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
                            / {{ totalWorksCount }}
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
                            {{ t('works.index.statUnderReview') }}
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
                                    ? `(${draftCount} ${t('works.index.filterDraft')})`
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
                            {{ t('works.index.statFiles') }}
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
                            {{ t('works.index.statFiles') }}
                        </span>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- 3. Toolbar & Filters -->
        <div
            class="flex flex-col gap-4 rounded-2xl border border-border/80 bg-card/60 p-4 shadow-xs backdrop-blur-xs md:flex-row md:items-center md:justify-between"
        >
            <!-- Left: Search & Filter Tabs -->
            <div class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                <!-- Search Box -->
                <div class="relative w-full sm:max-w-xs">
                    <Search
                        class="pointer-events-none absolute start-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="searchQuery"
                        type="text"
                        :placeholder="t('works.index.searchPlaceholder')"
                        class="input-premium h-10 rounded-xl ps-9.5 text-xs sm:text-sm"
                    />
                </div>

                <!-- Status Tabs -->
                <div
                    class="flex items-center gap-1 overflow-x-auto rounded-xl border border-border/60 bg-muted/40 p-1 text-xs"
                >
                    <button
                        type="button"
                        :class="[
                            'cursor-pointer rounded-lg px-3 py-1.5 font-medium transition-all',
                            selectedStatus === 'all'
                                ? 'bg-background font-semibold text-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="selectedStatus = 'all'"
                    >
                        {{ t('works.index.filterAll') }}
                        <span class="ms-1 text-[10px] opacity-70"
                            >({{ totalWorksCount }})</span
                        >
                    </button>
                    <button
                        type="button"
                        :class="[
                            'cursor-pointer rounded-lg px-3 py-1.5 font-medium transition-all',
                            selectedStatus === 'registered'
                                ? 'bg-background font-semibold text-emerald-600 shadow-xs dark:text-emerald-400'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="selectedStatus = 'registered'"
                    >
                        {{ t('works.index.filterRegistered') }}
                        <span class="ms-1 text-[10px] opacity-70"
                            >({{ registeredCount }})</span
                        >
                    </button>
                    <button
                        type="button"
                        :class="[
                            'cursor-pointer rounded-lg px-3 py-1.5 font-medium transition-all',
                            selectedStatus === 'under_review'
                                ? 'bg-background font-semibold text-amber-600 shadow-xs dark:text-amber-400'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="selectedStatus = 'under_review'"
                    >
                        {{ t('works.index.filterUnderReview') }}
                        <span class="ms-1 text-[10px] opacity-70"
                            >({{ underReviewCount }})</span
                        >
                    </button>
                    <button
                        type="button"
                        :class="[
                            'cursor-pointer rounded-lg px-3 py-1.5 font-medium transition-all',
                            selectedStatus === 'draft'
                                ? 'bg-background font-semibold text-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="selectedStatus = 'draft'"
                    >
                        {{ t('works.index.filterDraft') }}
                        <span class="ms-1 text-[10px] opacity-70"
                            >({{ draftCount }})</span
                        >
                    </button>
                </div>
            </div>

            <!-- Right: Sort & Grid/List View Toggles -->
            <div
                class="flex items-center justify-between gap-2.5 sm:justify-end"
            >
                <!-- Sort Dropdown -->
                <div class="relative flex items-center">
                    <ArrowUpDown
                        class="pointer-events-none absolute start-3 size-3.5 text-muted-foreground"
                    />
                    <select
                        v-model="selectedSort"
                        class="input-premium h-10 cursor-pointer appearance-none rounded-xl bg-background ps-8 pe-8 text-xs font-medium text-foreground focus:outline-none"
                    >
                        <option value="newest">
                            {{ t('works.index.sortNewest') }}
                        </option>
                        <option value="oldest">
                            {{ t('works.index.sortOldest') }}
                        </option>
                        <option value="title">
                            {{ t('works.index.sortTitle') }}
                        </option>
                        <option value="files">
                            {{ t('works.index.sortFiles') }}
                        </option>
                    </select>
                </div>

                <!-- View Mode Switcher -->
                <div
                    class="flex items-center rounded-xl border border-border/60 bg-muted/40 p-1"
                >
                    <button
                        type="button"
                        :class="[
                            'cursor-pointer rounded-lg p-1.5 transition-all',
                            viewMode === 'grid'
                                ? 'bg-background text-onda-blue-600 shadow-xs dark:text-onda-blue-400'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        :title="t('works.index.grid')"
                        @click="viewMode = 'grid'"
                    >
                        <Grid3X3 class="size-4" />
                    </button>
                    <button
                        type="button"
                        :class="[
                            'cursor-pointer rounded-lg p-1.5 transition-all',
                            viewMode === 'list'
                                ? 'bg-background text-onda-blue-600 shadow-xs dark:text-onda-blue-400'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        :title="t('works.index.list')"
                        @click="viewMode = 'list'"
                    >
                        <List class="size-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- 4. Main Content Area -->
        <!-- A. ZERO WORKS STATE (No Works at all in account) -->
        <div
            v-if="props.works.length === 0"
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
                        {{ t('works.index.empty') }}
                    </h3>
                    <p
                        class="text-xs leading-relaxed text-muted-foreground sm:text-sm"
                    >
                        {{ t('works.index.emptyDescription') }}
                    </p>
                </div>

                <div class="pt-2">
                    <Button
                        class="h-11 cursor-pointer gap-2 rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 px-6 font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all duration-200 hover:-translate-y-0.5 hover:from-onda-blue-700 hover:to-onda-blue-800 hover:shadow-onda-blue-600/40"
                        @click="isQuickCreateOpen = true"
                    >
                        <Plus class="size-4" />
                        <span>{{ t('works.index.emptyAction') }}</span>
                    </Button>
                </div>
            </div>
        </div>

        <!-- B. NO FILTER RESULTS STATE -->
        <div
            v-else-if="filteredWorks.length === 0"
            class="rounded-3xl border border-dashed border-border/80 bg-card/40 p-10 text-center backdrop-blur-xs sm:p-14"
        >
            <div class="mx-auto max-w-sm space-y-4">
                <div
                    class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-muted/60 text-muted-foreground"
                >
                    <Search class="size-7" />
                </div>
                <div class="space-y-1">
                    <h3 class="text-base font-bold text-foreground">
                        {{ t('works.index.noSearchResults') }}
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        {{ t('works.index.searchPlaceholder') }}
                    </p>
                </div>
                <Button
                    variant="outline"
                    class="cursor-pointer gap-1.5 rounded-xl text-xs font-semibold"
                    @click="resetFilters"
                >
                    <RotateCcw class="size-3.5" />
                    <span>{{ t('works.index.resetFilters') }}</span>
                </Button>
            </div>
        </div>

        <!-- C. GRID VIEW MODE -->
        <div
            v-else-if="viewMode === 'grid'"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <div
                v-for="work in filteredWorks"
                :key="work.uuid"
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
                                    getWorkCategoryMeta(
                                        work.title,
                                        work.description,
                                    ).colorClass,
                                ]"
                            >
                                <component
                                    :is="
                                        getWorkCategoryMeta(
                                            work.title,
                                            work.description,
                                        ).icon
                                    "
                                    class="size-3.5"
                                />
                            </div>
                            <span
                                :class="[
                                    'rounded-md border px-2 py-0.5 text-[11px] font-semibold',
                                    getWorkCategoryMeta(
                                        work.title,
                                        work.description,
                                    ).badgeClass,
                                ]"
                            >
                                {{
                                    getWorkCategoryMeta(
                                        work.title,
                                        work.description,
                                    ).label
                                }}
                            </span>
                        </div>

                        <!-- Status Badge with Pulse -->
                        <Badge
                            variant="outline"
                            :class="[
                                'gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold',
                                getStatusMeta(work.status).class,
                            ]"
                        >
                            <span
                                v-if="getStatusMeta(work.status).pulse"
                                :class="[
                                    'size-1.5 animate-pulse rounded-full',
                                    getStatusMeta(work.status).dotClass,
                                ]"
                            />
                            <span
                                v-else
                                :class="[
                                    'size-1.5 rounded-full',
                                    getStatusMeta(work.status).dotClass,
                                ]"
                            />
                            <span>{{ getStatusMeta(work.status).label }}</span>
                        </Badge>
                    </div>

                    <!-- Work Title & Description -->
                    <div class="mt-4 space-y-1.5">
                        <Link
                            :href="show(work.uuid)"
                            class="block group-hover:text-onda-blue-600 dark:group-hover:text-onda-blue-400"
                        >
                            <h2
                                class="line-clamp-1 text-base font-bold tracking-tight text-foreground transition-colors"
                                :title="work.title"
                            >
                                {{ work.title }}
                            </h2>
                        </Link>
                        <p
                            class="line-clamp-2 text-xs leading-relaxed text-muted-foreground"
                            :title="work.description || ''"
                        >
                            {{
                                work.description ||
                                t('works.index.noDescription')
                            }}
                        </p>
                    </div>
                </div>

                <!-- Meta Pills & Footer Actions -->
                <div class="mt-5 space-y-3.5 border-t border-border/60 pt-4">
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
                                t('works.index.fileCount', {
                                    count: work.media_files_count || 0,
                                })
                            }}</span>
                        </div>

                        <span
                            class="font-mono text-[11px] text-muted-foreground"
                        >
                            {{ formatDate(work.created_at) }}
                        </span>
                    </div>

                    <!-- Bottom Action Buttons -->
                    <div class="flex items-center justify-between gap-2">
                        <!-- Quick Copy UUID Button -->
                        <button
                            type="button"
                            class="flex cursor-pointer items-center gap-1 rounded-lg border border-border/80 bg-muted/40 px-2 py-1.5 font-mono text-[11px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            :title="work.uuid"
                            @click="copyWorkUuid(work.uuid)"
                        >
                            <component
                                :is="copiedUuid === work.uuid ? Check : Copy"
                                :class="[
                                    'size-3 shrink-0',
                                    copiedUuid === work.uuid
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-muted-foreground',
                                ]"
                            />
                            <span class="max-w-[75px] truncate sm:max-w-[90px]">
                                {{
                                    copiedUuid === work.uuid
                                        ? t('works.index.uuidCopied')
                                        : work.uuid.slice(0, 8) + '...'
                                }}
                            </span>
                        </button>

                        <!-- Enter Work Details / Upload Hub -->
                        <Button
                            as-child
                            size="sm"
                            class="h-8.5 cursor-pointer gap-1.5 rounded-xl bg-onda-blue-600/10 px-3 text-xs font-semibold text-onda-blue-700 transition-all hover:bg-onda-blue-600 hover:text-white dark:bg-onda-blue-500/20 dark:text-onda-blue-300 dark:hover:bg-onda-blue-500 dark:hover:text-gray-950"
                        >
                            <Link :href="show(work.uuid)">
                                <span>{{ t('works.index.manageWork') }}</span>
                                <ExternalLink class="size-3 rtl:rotate-180" />
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- D. STRUCTURED REGISTRY TABLE (LIST MODE) -->
        <div
            v-else-if="viewMode === 'list'"
            class="overflow-hidden rounded-2xl border border-border/80 bg-card shadow-xs"
        >
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-start text-xs">
                    <thead>
                        <tr
                            class="border-b border-border/70 bg-muted/40 font-semibold text-muted-foreground"
                        >
                            <th class="px-4 py-3.5 text-start font-medium">
                                {{ t('works.index.title') }}
                            </th>
                            <th
                                class="hidden px-4 py-3.5 text-start font-medium md:table-cell"
                            >
                                {{ t('works.index.copyUuid') }}
                            </th>
                            <th class="px-4 py-3.5 text-start font-medium">
                                {{ t('works.index.statFiles') }}
                            </th>
                            <th
                                class="hidden px-4 py-3.5 text-start font-medium sm:table-cell"
                            >
                                {{ t('works.index.createdOn', { date: '' }) }}
                            </th>
                            <th class="px-4 py-3.5 text-start font-medium">
                                {{ t('dashboard.table.colStatus') }}
                            </th>
                            <th class="px-4 py-3.5 text-end font-medium">
                                {{ t('dashboard.table.colActions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr
                            v-for="work in filteredWorks"
                            :key="work.uuid"
                            class="group transition-colors hover:bg-accent/40"
                        >
                            <!-- Title & Category Icon -->
                            <td class="px-4 py-3.5">
                                <Link
                                    :href="show(work.uuid)"
                                    class="flex items-center gap-3"
                                >
                                    <div
                                        :class="[
                                            'flex size-8 shrink-0 items-center justify-center rounded-lg border',
                                            getWorkCategoryMeta(
                                                work.title,
                                                work.description,
                                            ).colorClass,
                                        ]"
                                    >
                                        <component
                                            :is="
                                                getWorkCategoryMeta(
                                                    work.title,
                                                    work.description,
                                                ).icon
                                            "
                                            class="size-4"
                                        />
                                    </div>
                                    <div class="min-w-0 space-y-0.5">
                                        <p
                                            class="max-w-xs truncate font-bold text-foreground transition-colors group-hover:text-onda-blue-600 sm:max-w-sm md:max-w-md dark:group-hover:text-onda-blue-400"
                                        >
                                            {{ work.title }}
                                        </p>
                                        <p
                                            v-if="work.description"
                                            class="max-w-xs truncate text-[11px] text-muted-foreground sm:max-w-sm"
                                        >
                                            {{ work.description }}
                                        </p>
                                    </div>
                                </Link>
                            </td>

                            <!-- UUID Reference -->
                            <td class="hidden px-4 py-3.5 md:table-cell">
                                <button
                                    type="button"
                                    class="flex cursor-pointer items-center gap-1.5 rounded-md bg-muted/60 px-2 py-1 font-mono text-[11px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                    :title="work.uuid"
                                    @click="copyWorkUuid(work.uuid)"
                                >
                                    <span>{{ work.uuid.slice(0, 10) }}...</span>
                                    <component
                                        :is="
                                            copiedUuid === work.uuid
                                                ? Check
                                                : Copy
                                        "
                                        :class="[
                                            'size-3 shrink-0',
                                            copiedUuid === work.uuid
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-muted-foreground',
                                        ]"
                                    />
                                </button>
                            </td>

                            <!-- Files Count -->
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                <span
                                    class="inline-flex items-center gap-1 rounded-md bg-muted/60 px-2 py-0.5 font-medium text-foreground"
                                >
                                    <Files
                                        class="size-3 text-onda-blue-600 dark:text-onda-blue-400"
                                    />
                                    {{ work.media_files_count || 0 }}
                                </span>
                            </td>

                            <!-- Date Created -->
                            <td
                                class="hidden px-4 py-3.5 font-mono whitespace-nowrap text-muted-foreground sm:table-cell"
                            >
                                {{ formatDate(work.created_at) }}
                            </td>

                            <!-- Status Badge -->
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                <Badge
                                    variant="outline"
                                    :class="[
                                        'gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold',
                                        getStatusMeta(work.status).class,
                                    ]"
                                >
                                    <span
                                        v-if="getStatusMeta(work.status).pulse"
                                        :class="[
                                            'size-1.5 animate-pulse rounded-full',
                                            getStatusMeta(work.status).dotClass,
                                        ]"
                                    />
                                    <span
                                        v-else
                                        :class="[
                                            'size-1.5 rounded-full',
                                            getStatusMeta(work.status).dotClass,
                                        ]"
                                    />
                                    <span>{{
                                        getStatusMeta(work.status).label
                                    }}</span>
                                </Badge>
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3.5 text-end whitespace-nowrap">
                                <Button
                                    as-child
                                    size="sm"
                                    variant="ghost"
                                    class="h-8 cursor-pointer gap-1.5 px-2.5 text-xs font-semibold text-onda-blue-600 hover:bg-onda-blue-500/10 hover:text-onda-blue-700 dark:text-onda-blue-400"
                                >
                                    <Link :href="show(work.uuid)">
                                        <span>{{
                                            t('works.index.manageWork')
                                        }}</span>
                                        <ExternalLink
                                            class="size-3 rtl:rotate-180"
                                        />
                                    </Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 5. Quick Work Creation Modal Dialog -->
    <Dialog :open="isQuickCreateOpen" @update:open="isQuickCreateOpen = $event">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <div
                    class="mb-2 flex size-11 items-center justify-center rounded-xl bg-onda-blue-500/10 text-onda-blue-600 dark:text-onda-blue-400"
                >
                    <Sparkles class="size-5.5" />
                </div>
                <DialogTitle class="text-lg font-bold tracking-tight">
                    {{ t('works.index.quickCreateTitle') }}
                </DialogTitle>
                <DialogDescription class="text-xs text-muted-foreground">
                    {{ t('works.index.quickCreateSubtitle') }}
                </DialogDescription>
            </DialogHeader>

            <form @submit.prevent="submitQuickCreate" class="space-y-4 py-2">
                <!-- Title Field -->
                <div class="space-y-1.5">
                    <Label for="quick-title" class="text-xs font-semibold">
                        {{ t('works.create.titleLabel') }}
                        <span class="text-destructive">*</span>
                    </Label>
                    <Input
                        id="quick-title"
                        v-model="form.title"
                        type="text"
                        required
                        autofocus
                        :placeholder="t('works.create.titlePlaceholder')"
                        class="input-premium h-10 text-xs sm:text-sm"
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <!-- Description Field -->
                <div class="space-y-1.5">
                    <Label
                        for="quick-description"
                        class="text-xs font-semibold"
                    >
                        {{ t('works.create.descriptionLabel') }}
                    </Label>
                    <textarea
                        id="quick-description"
                        v-model="form.description"
                        rows="3"
                        :placeholder="t('works.create.descriptionPlaceholder')"
                        class="flex w-full rounded-xl border border-input bg-background/50 px-3.5 py-2.5 text-xs shadow-xs transition-all outline-none placeholder:text-muted-foreground focus-visible:border-onda-blue-600 focus-visible:ring-4 focus-visible:ring-onda-blue-600/20 sm:text-sm dark:bg-input/20"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <DialogFooter class="mt-4 gap-2 sm:gap-0">
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="outline"
                            class="cursor-pointer rounded-xl text-xs"
                        >
                            {{ t('works.index.cancel') }}
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        class="cursor-pointer rounded-xl bg-gradient-to-r from-onda-blue-600 to-onda-blue-700 px-5 text-xs font-semibold text-white shadow-md hover:from-onda-blue-700 hover:to-onda-blue-800"
                    >
                        {{
                            form.processing
                                ? t('works.create.submitting')
                                : t('works.create.submit')
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
