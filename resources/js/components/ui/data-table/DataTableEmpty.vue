<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import { SearchX } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface Props {
    title?: string;
    description?: string;
    icon?: Component;
    actionText?: string;
    /** Offer "Effacer les filtres" only when there is something to clear. */
    hasActiveFilters?: boolean;
    class?: string;
}

// No `withDefaults` for `icon`: Vue treats a function default as a FACTORY,
// and a lucide icon is a function (a functional component). It was called
// with no component instance and threw "Cannot destructure property 'slots'
// of 'undefined'" in the middle of the patch that swaps a list for this
// empty state — which left the whole page unable to re-render until a
// reload. The fallback is resolved here instead.
const props = withDefaults(defineProps<Props>(), {
    hasActiveFilters: true,
});

const resolvedIcon = computed<Component>(() => props.icon ?? SearchX);

const emit = defineEmits<{
    (e: 'clear-filters'): void;
}>();

const { t } = useI18n();
</script>

<template>
    <div
        data-test="list-empty"
        :class="
            cn(
                'flex flex-col items-center justify-center p-8 text-center sm:p-14 select-none',
                $props.class,
            )
        "
    >
        <div
            class="mb-3.5 flex size-12 items-center justify-center rounded-xl bg-muted/60 text-muted-foreground"
        >
            <component :is="resolvedIcon" :stroke-width="1.75" class="size-6 text-muted-foreground/80" />
        </div>

        <h3 class="text-sm font-semibold text-foreground">
            {{ title || t('common.noResults') }}
        </h3>

        <p class="mt-1 max-w-sm text-xs leading-relaxed text-muted-foreground">
            {{ description || t('common.noResultsDescription') }}
        </p>

        <div
            v-if="hasActiveFilters"
            class="mt-4 flex items-center justify-center gap-2"
        >
            <Button
                variant="outline"
                size="sm"
                data-test="list-empty-clear"
                class="h-8 gap-1.5 rounded-lg text-xs font-medium cursor-pointer"
                @click="emit('clear-filters')"
            >
                {{ actionText || t('common.clearFilters') }}
            </Button>
        </div>
    </div>
</template>
