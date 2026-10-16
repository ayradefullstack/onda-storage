<script setup lang="ts">
import { cn } from '@/lib/utils';
import TableScroll from './TableScroll.vue';

interface Props {
    /** A filter/page visit is in flight: the rows dim until it lands. */
    loading?: boolean;
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <div
        :class="
            cn(
                'relative overflow-hidden rounded-xl border border-border/80 bg-card shadow-xs transition-colors',
                $props.class,
            )
        "
        :aria-busy="loading ? 'true' : undefined"
        :data-loading="loading ? '' : undefined"
    >
        <!-- Top Toolbar Slot -->
        <slot name="toolbar" />

        <!-- Table Viewport (Horizontal Scroll on Mobile/Dense Grids) -->
        <!-- Dimmed, never hidden: the previous rows stay readable until the
             new ones replace them. -->
        <TableScroll
            :class="
                cn(
                    'transition-opacity duration-150',
                    loading && 'pointer-events-none opacity-60',
                )
            "
        >
            <slot />
        </TableScroll>

        <!-- Pagination Slot -->
        <slot name="pagination" />
    </div>
</template>
