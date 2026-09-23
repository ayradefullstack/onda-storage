<script setup lang="ts">
import { computed } from 'vue';
import { RAIL_LENGTH } from '@/components/upload/depositJourney';
import type { RailTone } from '@/components/upload/depositJourney';

const props = withDefaults(
    defineProps<{
        stepIndex: number;
        tone?: RailTone;
    }>(),
    {
        tone: 'active',
    },
);

const steps = computed(() => Array.from({ length: RAIL_LENGTH }, (_, i) => i));

function dotClass(step: number): string {
    if (step < props.stepIndex) {
        return 'bg-onda-teal-600';
    }

    if (step === props.stepIndex) {
        if (props.tone === 'paused') {
            return 'bg-muted-foreground/60';
        }

        return step === RAIL_LENGTH - 1
            ? 'bg-onda-teal-600'
            : 'bg-primary animate-pulse';
    }

    return 'bg-muted';
}

function connectorClass(step: number): string {
    return step < props.stepIndex ? 'bg-onda-teal-600' : 'bg-muted';
}
</script>

<template>
    <div
        class="flex items-center"
        role="img"
        :aria-label="`${stepIndex + 1}/${RAIL_LENGTH}`"
    >
        <template v-for="step in steps" :key="step">
            <span
                class="size-2 shrink-0 rounded-full transition-colors duration-300"
                :class="dotClass(step)"
            />
            <span
                v-if="step < steps.length - 1"
                class="h-px w-4 shrink-0 transition-colors duration-300"
                :class="connectorClass(step)"
            />
        </template>
    </div>
</template>
