<script setup lang="ts">
import { cn } from '@/lib/utils';

interface Props {
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
    >
        <!-- Top Toolbar Slot -->
        <slot name="toolbar" />

        <!-- Table Viewport (Horizontal Scroll on Mobile/Dense Grids) -->
        <div class="relative w-full overflow-x-auto">
            <slot />
        </div>

        <!-- Pagination Slot -->
        <slot name="pagination" />
    </div>
</template>
