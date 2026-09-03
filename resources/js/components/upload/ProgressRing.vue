<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        percent: number;
        size?: number;
        strokeWidth?: number;
    }>(),
    {
        size: 40,
        strokeWidth: 4,
    },
);

const clampedPercent = computed(() =>
    Math.min(100, Math.max(0, props.percent)),
);
const radius = computed(() => (props.size - props.strokeWidth) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);
const dashOffset = computed(
    () => circumference.value * (1 - clampedPercent.value / 100),
);
</script>

<template>
    <div
        class="relative inline-flex shrink-0 items-center justify-center"
        :style="{ width: `${size}px`, height: `${size}px` }"
    >
        <svg
            :width="size"
            :height="size"
            :viewBox="`0 0 ${size} ${size}`"
            class="-rotate-90"
            role="img"
            :aria-valuenow="Math.round(clampedPercent)"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <circle
                :cx="size / 2"
                :cy="size / 2"
                :r="radius"
                fill="none"
                stroke="currentColor"
                class="text-muted-foreground/20"
                :stroke-width="strokeWidth"
            />
            <circle
                :cx="size / 2"
                :cy="size / 2"
                :r="radius"
                fill="none"
                stroke="currentColor"
                class="text-primary transition-[stroke-dashoffset] duration-300 ease-out"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="dashOffset"
            />
        </svg>
        <span class="absolute text-[0.6rem] font-medium tabular-nums"
            >{{ Math.round(clampedPercent) }}%</span
        >
    </div>
</template>
