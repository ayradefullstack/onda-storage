<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number;
    hint?: string;
}>();
</script>

<template>
    <div class="rounded-lg border border-border bg-card p-4">
        <p
            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
        >
            {{ label }}
        </p>
        <p class="mt-1 text-2xl font-semibold tracking-tight">
            <bdi dir="ltr">{{ value }}</bdi>
        </p>
        <p v-if="hint" class="mt-1 text-xs text-muted-foreground">
            {{ hint }}
        </p>
    </div>
</template>
