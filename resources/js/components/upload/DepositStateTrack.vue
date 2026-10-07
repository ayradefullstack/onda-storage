<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { RAIL_LENGTH } from '@/components/upload/depositJourney';
import type { RailTone } from '@/components/upload/depositJourney';

const props = withDefaults(
    defineProps<{
        stepIndex: number;
        tone?: RailTone;
        compact?: boolean;
    }>(),
    {
        tone: 'active',
        compact: false,
    },
);

const { t } = useI18n();

const stepLabels = computed(() =>
    [
        'initialisation',
        'upload',
        'assembly',
        'antivirus',
        'processing',
        'sealed',
    ].map((step) => t(`upload.step.${step}`)),
);

const steps = computed(() => Array.from({ length: RAIL_LENGTH }, (_, i) => i));

function nodeClass(step: number): string {
    if (step < props.stepIndex) {
        return 'bg-emerald-600 text-white dark:bg-emerald-500 shadow-xs';
    }

    if (step === props.stepIndex) {
        if (props.tone === 'paused') {
            return 'bg-amber-500 text-white ring-2 ring-amber-500/20';
        }

        return step === RAIL_LENGTH - 1
            ? 'bg-emerald-600 text-white dark:bg-emerald-500 shadow-xs'
            : 'bg-onda-blue-600 text-white shadow-xs ring-4 ring-onda-blue-500/25 animate-pulse';
    }

    return 'bg-muted text-muted-foreground/50 border border-border/80';
}

function connectorClass(step: number): string {
    if (step < props.stepIndex) {
        return 'bg-emerald-600 dark:bg-emerald-500';
    }

    return 'bg-border/80';
}
</script>

<template>
    <div
        class="flex items-center gap-1.5 py-1"
        role="img"
        :aria-label="`${stepLabels[stepIndex] ?? stepIndex + 1} (${stepIndex + 1}/${RAIL_LENGTH})`"
    >
        <template v-for="step in steps" :key="step">
            <!-- Node -->
            <div
                class="group relative flex size-4.5 shrink-0 items-center justify-center rounded-full text-[9px] font-bold transition-all duration-300"
                :class="nodeClass(step)"
                :title="`${step + 1}. ${stepLabels[step]}`"
            >
                <Check
                    v-if="
                        step < stepIndex ||
                        (step === stepIndex && step === RAIL_LENGTH - 1)
                    "
                    class="size-2.5 stroke-[3]"
                />
                <span v-else>{{ step + 1 }}</span>

                <!-- Hover Tooltip -->
                <div
                    class="pointer-events-none absolute -bottom-6 left-1/2 z-20 -translate-x-1/2 rounded-md bg-foreground px-1.5 py-0.5 text-[9px] font-medium whitespace-nowrap text-background opacity-0 shadow-md transition-opacity duration-150 group-hover:opacity-100"
                >
                    {{ stepLabels[step] }}
                </div>
            </div>

            <!-- Connector -->
            <div
                v-if="step < steps.length - 1"
                class="h-0.5 max-w-7 min-w-3 flex-1 rounded-full transition-colors duration-300"
                :class="connectorClass(step)"
            />
        </template>

        <span
            class="ms-2 hidden font-mono text-[10px] text-muted-foreground sm:inline-block"
        >
            {{ stepLabels[stepIndex] ?? '' }}
        </span>
    </div>
</template>
