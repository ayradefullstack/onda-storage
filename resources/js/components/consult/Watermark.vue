<script setup lang="ts">
import { computed } from 'vue';

/**
 * A repeated, low-opacity overlay naming the viewing admin and the moment —
 * a deterrent that makes a leak attributable, not protection: it is CSS on
 * top of the derivative, not burned into it. Click-through, never selectable.
 */
const props = defineProps<{ label: string }>();

const text = computed(
    () =>
        `${props.label} · ${new Date().toISOString().slice(0, 16).replace('T', ' ')}`,
);
</script>

<template>
    <div
        class="pointer-events-none absolute inset-0 z-10 grid grid-cols-2 content-around justify-items-center overflow-hidden opacity-[0.12] select-none md:grid-cols-3"
        aria-hidden="true"
        data-testid="watermark"
    >
        <span
            v-for="n in 12"
            :key="n"
            class="-rotate-[24deg] py-10 font-mono text-xs whitespace-nowrap"
            dir="ltr"
        >
            {{ text }}
        </span>
    </div>
</template>
