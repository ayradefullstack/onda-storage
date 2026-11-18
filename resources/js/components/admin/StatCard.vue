<script setup lang="ts">
import { CountUp } from '@/components/ui/count-up';

defineProps<{
    label: string;
    value: string | number;
    hint?: string;
}>();
</script>

<template>
    <div class="motion-lift rounded-lg border border-border bg-card p-4">
        <p
            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
        >
            {{ label }}
        </p>
        <p class="mt-1 text-2xl font-semibold tracking-tight">
            <bdi dir="ltr"><CountUp :value="value" /></bdi>
        </p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">
            {{ hint }}
        </p>
    </div>
</template>
