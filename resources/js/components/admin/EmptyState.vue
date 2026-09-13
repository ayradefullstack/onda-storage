<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-border px-6 py-14 text-center"
    >
        <p class="text-sm font-medium">{{ title }}</p>
        <p v-if="description" class="max-w-sm text-xs text-muted-foreground">
            {{ description }}
        </p>
        <slot />
    </div>
</template>
