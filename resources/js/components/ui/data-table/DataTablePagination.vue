<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

export interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface PaginatedData {
    from: number | null;
    to: number | null;
    total: number;
    links?: PageLink[];
    current_page?: number;
    last_page?: number;
    per_page?: number;
}

interface Props {
    paginated?: PaginatedData;
    links?: PageLink[];
    from?: number | null;
    to?: number | null;
    total?: number;
    currentPage?: number;
    lastPage?: number;
    perPage?: number;
    pageSizeOptions?: number[];
    showPageSize?: boolean;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    pageSizeOptions: () => [10, 15, 25, 50, 100],
    showPageSize: false,
});

const emit = defineEmits<{
    (e: 'update:perPage', size: number): void;
    (e: 'page-change', page: number): void;
}>();

const { t } = useI18n();

const resolvedFrom = computed(() => props.paginated?.from ?? props.from ?? null);
const resolvedTo = computed(() => props.paginated?.to ?? props.to ?? null);
const resolvedTotal = computed(() => props.paginated?.total ?? props.total ?? 0);
const resolvedLinks = computed(() => props.paginated?.links ?? props.links ?? []);
const resolvedPerPage = computed(() => props.paginated?.per_page ?? props.perPage);

const prevLink = computed(() => {
    if (!resolvedLinks.value || resolvedLinks.value.length < 2) return null;
    return resolvedLinks.value[0];
});

const nextLink = computed(() => {
    if (!resolvedLinks.value || resolvedLinks.value.length < 2) return null;
    return resolvedLinks.value[resolvedLinks.value.length - 1];
});

const numericLinks = computed(() => {
    if (!resolvedLinks.value || resolvedLinks.value.length <= 2) return [];
    // Slice off the first (prev) and last (next) links
    return resolvedLinks.value.slice(1, resolvedLinks.value.length - 1);
});

const handlePageSizeChange = (val: unknown) => {
    if (val === null || val === undefined) return;
    const size = parseInt(String(val), 10);
    if (isNaN(size)) return;
    emit('update:perPage', size);

    // If on Inertia page with server-side per_page query param:
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('per_page', String(size));
    currentUrl.searchParams.set('page', '1');

    router.get(
        currentUrl.pathname + currentUrl.search,
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
</script>

<template>
    <nav
        :class="
            cn(
                'flex flex-col gap-3 border-t border-border/70 bg-muted/10 px-4 py-3 sm:flex-row sm:items-center sm:justify-between select-none',
                $props.class,
            )
        "
        aria-label="Pagination"
    >
        <!-- Left: Results Counter & Optional Page Size Selector -->
        <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            <p v-if="resolvedTotal > 0">
                <i18n-t keypath="admin.pagination.showing" tag="span">
                    <template #from>
                        <span class="font-semibold text-foreground">{{ resolvedFrom ?? 0 }}</span>
                    </template>
                    <template #to>
                        <span class="font-semibold text-foreground">{{ resolvedTo ?? 0 }}</span>
                    </template>
                    <template #total>
                        <span class="font-semibold text-foreground">{{ resolvedTotal }}</span>
                    </template>
                </i18n-t>
            </p>
            <p v-else>
                0 {{ t('common.results', 'résultats') }}
            </p>

            <!-- Optional Page Size Selector (Flowbite PM Table style) -->
            <div
                v-if="showPageSize && resolvedPerPage"
                class="flex items-center gap-1.5"
            >
                <span class="text-muted-foreground/60">·</span>
                <span class="text-[11px] text-muted-foreground">{{ t('admin.pagination.perPage') }}</span>
                <Select
                    :model-value="String(resolvedPerPage)"
                    @update:model-value="handlePageSizeChange"
                >
                    <SelectTrigger size="sm" class="h-7 w-16 text-xs px-2">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opt in pageSizeOptions"
                            :key="opt"
                            :value="String(opt)"
                        >
                            {{ opt }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <!-- Right: Page Buttons & Controls -->
        <div
            v-if="resolvedLinks && resolvedLinks.length > 3"
            class="flex items-center gap-1 self-center sm:self-auto"
        >
            <!-- Previous Button -->
            <component
                :is="prevLink?.url ? Link : 'span'"
                :href="prevLink?.url ?? undefined"
                preserve-scroll
                :class="
                    cn(
                        'inline-flex h-8 items-center justify-center gap-1 rounded-lg border px-2.5 text-xs font-medium transition-colors duration-150',
                        prevLink?.url
                            ? 'cursor-pointer border-border/80 bg-background text-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring'
                            : 'pointer-events-none cursor-not-allowed border-transparent text-muted-foreground/40',
                    )
                "
                :aria-disabled="!prevLink?.url"
            >
                <ChevronLeft class="size-3.5 rtl:rotate-180" />
                <span class="hidden sm:inline">{{ t('dashboard.table.prev', 'Précédent') }}</span>
            </component>

            <!-- Numeric Page Buttons -->
            <div class="flex items-center gap-1">
                <template v-for="(link, index) in numericLinks" :key="index">
                    <!-- Ellipsis / Inactive link -->
                    <span
                        v-if="link.url === null"
                        class="inline-flex h-8 min-w-8 items-center justify-center px-1 text-xs text-muted-foreground/60 select-none"
                    >
                        ...
                    </span>

                    <!-- Page Number Link -->
                    <Link
                        v-else
                        :href="link.url"
                        preserve-scroll
                        :class="
                            cn(
                                'inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-xs font-medium transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/40',
                                link.active
                                    ? 'bg-primary text-primary-foreground font-semibold shadow-xs'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                            )
                        "
                        :aria-current="link.active ? 'page' : undefined"
                    >
                        <span v-html="link.label" />
                    </Link>
                </template>
            </div>

            <!-- Next Button -->
            <component
                :is="nextLink?.url ? Link : 'span'"
                :href="nextLink?.url ?? undefined"
                preserve-scroll
                :class="
                    cn(
                        'inline-flex h-8 items-center justify-center gap-1 rounded-lg border px-2.5 text-xs font-medium transition-colors duration-150',
                        nextLink?.url
                            ? 'cursor-pointer border-border/80 bg-background text-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring'
                            : 'pointer-events-none cursor-not-allowed border-transparent text-muted-foreground/40',
                    )
                "
                :aria-disabled="!nextLink?.url"
            >
                <span class="hidden sm:inline">{{ t('dashboard.table.next', 'Suivant') }}</span>
                <ChevronRight class="size-3.5 rtl:rotate-180" />
            </component>
        </div>
    </nav>
</template>
