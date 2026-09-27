<script setup lang="ts">
import { computed } from 'vue';
import { RotateCcw, X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { cn } from '@/lib/utils';

export interface ActiveFilter {
    key: string;
    label: string;
    value?: string;
}

interface Props {
    filters: ActiveFilter[];
    class?: string;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    (e: 'remove', key: string): void;
    (e: 'clear'): void;
    (e: 'clear-all'): void;
}>();

const { t } = useI18n();

const hasFilters = computed(() => props.filters && props.filters.length > 0);
</script>

<template>
    <div
        v-if="hasFilters"
        :class="
            cn(
                'flex flex-wrap items-center gap-1.5 pt-2 text-xs',
                $props.class,
            )
        "
        aria-label="Filtres actifs"
    >
        <span class="text-[11px] font-medium text-muted-foreground me-1 select-none">
            {{ t('common.activeFilters', 'Filtres actifs') }}:
        </span>

        <!-- Filter Pills -->
        <span
            v-for="filter in filters"
            :key="filter.key"
            class="inline-flex items-center gap-1 rounded-md border border-border/70 bg-muted/60 px-2 py-0.5 text-xs text-foreground transition-colors hover:bg-muted"
        >
            <span v-if="filter.value" class="text-muted-foreground">{{ filter.label }}:</span>
            <span :class="filter.value ? 'font-medium' : ''">{{ filter.value ?? filter.label }}</span>
            <button
                type="button"
                class="ms-0.5 -me-0.5 flex size-3.5 cursor-pointer items-center justify-center rounded-sm text-muted-foreground/80 hover:bg-background/80 hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                :title="t('common.removeFilter', 'Supprimer ce filtre')"
                :aria-label="t('common.removeFilter', 'Supprimer ce filtre')"
                @click="emit('remove', filter.key)"
            >
                <X :stroke-width="2" class="size-3" />
            </button>
        </span>

        <!-- Clear All Button -->
        <button
            type="button"
            class="ms-1 inline-flex items-center gap-1 cursor-pointer rounded-md px-1.5 py-0.5 text-xs font-medium text-primary hover:text-primary/80 hover:underline focus-visible:outline-none"
            @click="emit('clear'); emit('clear-all')"
        >
            <RotateCcw :stroke-width="1.75" class="size-3" />
            <span>{{ t('common.clearAll', 'Tout effacer') }}</span>
        </button>
    </div>
</template>
