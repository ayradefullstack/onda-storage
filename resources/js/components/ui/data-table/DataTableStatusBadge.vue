<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

export type StatusTone =
    | 'success'
    | 'warning'
    | 'destructive'
    | 'info'
    | 'neutral'
    | 'primary';

interface Props {
    status?: string;
    label?: string;
    tone?: StatusTone;
    pulse?: boolean;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    pulse: false,
});

const resolvedTone = computed<StatusTone>(() => {
    if (props.tone) return props.tone;
    const s = (props.status ?? '').toLowerCase();

    switch (s) {
        case 'approved':
        case 'registered':
        case 'ready':
        case 'clean':
        case 'paid':
        case 'active':
        case 'success':
            return 'success';

        case 'under_review':
        case 'in_review':
            return 'info';

        case 'pending':
        case 'submitted':
        case 'processing':
        case 'waiting':
            return 'warning';

        case 'rejected':
        case 'failed':
        case 'infected':
        case 'error':
        case 'cancelled':
            return 'destructive';

        case 'draft':
        case 'inactive':
        case 'retired':
        case 'unknown':
            return 'neutral';

        case 'distributed':
        case 'streaming':
        case 'in_flight':
            return 'primary';

        default:
            return 'neutral';
    }
});

const toneClasses = computed(() => {
    switch (resolvedTone.value) {
        case 'success':
            return {
                badge: 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 dark:bg-emerald-500/15',
                dot: 'bg-emerald-500',
            };
        case 'warning':
            return {
                badge: 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-300 dark:bg-amber-500/15',
                dot: 'bg-amber-500',
            };
        case 'destructive':
            return {
                badge: 'border-rose-500/25 bg-rose-500/10 text-rose-700 dark:text-rose-300 dark:bg-rose-500/15',
                dot: 'bg-rose-500',
            };
        case 'primary':
            return {
                badge: 'border-onda-blue-500/25 bg-onda-blue-500/10 text-onda-blue-700 dark:text-onda-blue-300 dark:bg-onda-blue-500/15',
                dot: 'bg-onda-blue-600 dark:bg-onda-blue-400',
            };
        case 'info':
            return {
                badge: 'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-300 dark:bg-sky-500/15',
                dot: 'bg-sky-500',
            };
        case 'neutral':
        default:
            return {
                badge: 'border-border/80 bg-muted/60 text-muted-foreground',
                dot: 'bg-muted-foreground/60',
            };
    }
});

const shouldPulse = computed(() => {
    if (props.pulse) return true;
    const s = (props.status ?? '').toLowerCase();
    return s === 'under_review' || s === 'processing' || s === 'in_flight';
});
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold tracking-wide select-none',
                toneClasses.badge,
                $props.class,
            )
        "
    >
        <span
            :class="
                cn(
                    'size-1.5 shrink-0 rounded-full',
                    toneClasses.dot,
                    shouldPulse ? 'animate-pulse' : '',
                )
            "
            aria-hidden="true"
        />
        <span>
            <slot>{{ label || status }}</slot>
        </span>
    </span>
</template>
