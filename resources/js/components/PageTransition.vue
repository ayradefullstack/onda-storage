<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * A quick fade/slide of the page content when Inertia swaps the page
 * component. Enter only: the new page mounts at once and animates in, so a
 * visit is never delayed by an exit animation, and a visit that keeps the
 * same component (filters, pagination, the next file) does not remount or
 * flash. The thin bar at the top during the request is Inertia's own.
 */
const page = usePage();
const name = computed(() => page.component);
</script>

<template>
    <div :key="name" class="motion-page flex flex-1 flex-col">
        <slot />
    </div>
</template>
