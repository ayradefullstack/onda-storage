<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

interface Props {
    title?: string;
    subtitle?: string;
    count?: number;
    icon?: Component;
    class?: string;
}

defineProps<Props>();
</script>

<template>
    <div
        :class="
            cn(
                'space-y-3.5 border-b border-border/70 p-4 sm:p-5',
                $props.class,
            )
        "
    >
        <!-- Primary Toolbar Row -->
        <div
            class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
        >
            <!-- Start: Title / Context Slot -->
            <div v-if="$slots.title || title" class="flex items-center gap-3">
                <slot name="title">
                    <div
                        v-if="icon"
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-border/80 bg-muted/30 text-primary"
                    >
                        <component :is="icon" :stroke-width="1.75" class="size-4.5" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-semibold tracking-tight text-foreground sm:text-lg">
                                {{ title }}
                            </h2>
                            <span
                                v-if="count !== undefined"
                                class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary"
                            >
                                {{ count }}
                            </span>
                        </div>
                        <p v-if="subtitle" class="mt-0.5 text-xs text-muted-foreground">
                            {{ subtitle }}
                        </p>
                    </div>
                </slot>
            </div>

            <!-- End: Search, Filters, and Action Buttons -->
            <div
                class="flex flex-1 flex-wrap items-center gap-2.5 sm:flex-nowrap lg:justify-end"
            >
                <slot name="search" />
                <slot name="filters" />
                <slot name="actions" />
            </div>
        </div>

        <!-- Optional Second Row: Tabs or Views -->
        <div v-if="$slots.tabs" class="pt-1">
            <slot name="tabs" />
        </div>

        <!-- Active Filter Pills Row -->
        <slot name="filter-pills" />
    </div>
</template>
