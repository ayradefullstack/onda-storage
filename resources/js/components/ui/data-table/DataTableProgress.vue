<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

interface Props {
    value: number;
    max?: number;
    label?: string;
    sublabel?: string;
    showPercentage?: boolean;
    size?: 'sm' | 'default';
    variant?: 'auto' | 'primary' | 'success' | 'warning' | 'destructive';
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    max: 100,
    showPercentage: false,
    size: 'sm',
    variant: 'auto',
});

const percentage = computed(() => {
    if (props.max <= 0) return 0;
    const p = Math.round((props.value / props.max) * 100);
    return Math.min(100, Math.max(0, p));
});

const resolvedVariant = computed(() => {
    if (props.variant !== 'auto') return props.variant;
    if (percentage.value >= 100) return 'success';
    if (percentage.value >= 50) return 'primary';
    return 'warning';
});

const barClasses = computed(() => {
    switch (resolvedVariant.value) {
        case 'success':
            return 'bg-emerald-500 dark:bg-emerald-400';
        case 'warning':
            return 'bg-amber-500 dark:bg-amber-400';
        case 'destructive':
            return 'bg-rose-500 dark:bg-rose-400';
        case 'primary':
        default:
            return 'bg-primary';
    }
});
</script>

<template>
    <div
        :class="cn('flex flex-col gap-1 min-w-[7rem] select-none', $props.class)"
        :aria-valuenow="value"
        :aria-valuemin="0"
        :aria-valuemax="max"
        role="progressbar"
    >
        <!-- Label and Metric Row -->
        <div class="flex items-center justify-between gap-2 text-xs">
            <span v-if="label" class="truncate font-medium text-foreground">
                {{ label }}
            </span>
            <span v-else-if="showPercentage" class="font-medium text-foreground">
                {{ percentage }}%
            </span>
            <span v-else class="font-medium text-foreground">
                {{ value }} / {{ max }}
            </span>

            <span
                v-if="sublabel"
                class="text-[11px] text-muted-foreground truncate"
            >
                {{ sublabel }}
            </span>
        </div>

        <!-- Track & Fill Bar -->
        <div
            :class="
                cn(
                    'w-full overflow-hidden rounded-full bg-muted/70',
                    size === 'sm' ? 'h-1.5' : 'h-2',
                )
            "
        >
            <div
                :class="
                    cn(
                        'h-full rounded-full transition-all duration-300 ease-out',
                        barClasses,
                    )
                "
                :style="{ width: `${percentage}%` }"
            />
        </div>
    </div>
</template>
