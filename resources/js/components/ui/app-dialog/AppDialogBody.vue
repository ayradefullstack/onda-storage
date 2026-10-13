<script setup lang="ts">
import { ScrollArea } from '@/components/ui/scroll-area';

/**
 * The scrolling part of an AppDialog: it takes the height the header and
 * footer leave, scrolls on its own (overscroll contained) and shows scroll
 * shadows when there is more above or below.
 */
defineProps<{ flush?: boolean }>();
</script>

<template>
    <ScrollArea
        data-slot="app-dialog-body"
        class="flex-auto"
        :viewport-class="flush ? '' : 'px-5 py-4'"
    >
        <slot />
    </ScrollArea>
</template>
