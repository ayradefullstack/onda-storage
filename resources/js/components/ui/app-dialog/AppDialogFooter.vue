<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/** The actions row of an AppDialog. Never scrolls away. */
const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <footer
        data-slot="app-dialog-footer"
        :class="
            cn(
                'flex shrink-0 flex-col-reverse gap-2 border-t px-5 py-3 sm:flex-row sm:justify-end',
                props.class,
            )
        "
    >
        <slot />
    </footer>
</template>
