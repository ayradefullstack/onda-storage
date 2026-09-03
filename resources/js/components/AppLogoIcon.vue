<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        src="/assets/logos/favicon.png"
        alt="ONDA"
        :class="['shrink-0 object-contain', className]"
        v-bind="$attrs"
    />
</template>
