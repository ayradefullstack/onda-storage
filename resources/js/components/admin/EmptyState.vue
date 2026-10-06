<script setup lang="ts">
import { Inbox } from '@lucide/vue';
import EmptyState from '@/components/ui/empty-state/EmptyState.vue';

/**
 * The icon is passed explicitly, never left to the base component's default.
 * `ui/empty-state` declares `icon: Inbox` through `withDefaults`, and Vue
 * treats a function default as a FACTORY: it calls the lucide icon with no
 * component instance and the page dies with "Cannot destructure property
 * 'slots' of 'undefined'". Every admin list reaches this component when it
 * has no rows (a search that matches nothing, a new college with no
 * documents yet), so a blank page there is not an edge case.
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <EmptyState :title="title" :description="description" :icon="Inbox">
        <slot />
    </EmptyState>
</template>
