<script setup lang="ts">
import { computed } from 'vue';
import { useCountUp } from '@/composables/useCountUp';

/**
 * A stat number that counts up once on first view. Only whole numbers are
 * animated; anything else (a date, "12 GB", "—") is printed as it is. The
 * digits are tabular, so the width does not jitter while counting.
 */
const props = defineProps<{ value: number | string }>();

const numeric = computed<number | null>(() => {
    if (typeof props.value === 'number') {
        return Number.isInteger(props.value) ? props.value : null;
    }

    return /^\d+$/.test(props.value) ? Number(props.value) : null;
});

const shown = useCountUp(() => numeric.value ?? 0);
</script>

<template>
    <span v-if="numeric !== null" class="tabular-nums" data-slot="count-up">{{
        shown
    }}</span>
    <template v-else>{{ value }}</template>
</template>
