<script setup lang="ts">
import EmptyState from '@/components/ui/empty-state/EmptyState.vue';

defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <EmptyState :title="title" :description="description">
        <slot />
    </EmptyState>
</template>
