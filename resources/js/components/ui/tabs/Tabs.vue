<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

export interface TabItem {
    value: string | number;
    label: string;
    count?: number | string;
    icon?: Component;
    disabled?: boolean;
}

interface Props {
    modelValue: string | number;
    tabs: TabItem[];
    variant?: 'segmented' | 'underline';
    size?: 'sm' | 'default';
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    variant: 'segmented',
    size: 'default',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | number): void;
    (e: 'change', value: string | number): void;
}>();

const selectTab = (tab: TabItem) => {
    if (tab.disabled) return;
    emit('update:modelValue', tab.value);
    emit('change', tab.value);
};

const handleKeyDown = (event: KeyboardEvent, index: number) => {
    const availableTabs = props.tabs.filter((t) => !t.disabled);
    if (!availableTabs.length) return;

    let targetIndex = -1;
    if (event.key === 'ArrowRight') {
        event.preventDefault();
        targetIndex = (index + 1) % props.tabs.length;
    } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        targetIndex = (index - 1 + props.tabs.length) % props.tabs.length;
    } else if (event.key === 'Home') {
        event.preventDefault();
        targetIndex = 0;
    } else if (event.key === 'End') {
        event.preventDefault();
        targetIndex = props.tabs.length - 1;
    }

    if (targetIndex >= 0 && !props.tabs[targetIndex].disabled) {
        selectTab(props.tabs[targetIndex]);
    }
};
</script>

<template>
    <!-- Segmented Control Variant -->
    <div
        v-if="variant === 'segmented'"
        role="tablist"
        :class="
            cn(
                'inline-flex items-center gap-1 rounded-lg border border-border/60 bg-muted/60 p-1 text-xs select-none',
                props.class,
            )
        "
    >
        <button
            v-for="(tab, index) in tabs"
            :key="tab.value"
            role="tab"
            type="button"
            :aria-selected="modelValue === tab.value"
            :disabled="tab.disabled"
            :class="
                cn(
                    'inline-flex items-center gap-1.5 rounded-md font-medium transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:pointer-events-none disabled:opacity-40 cursor-pointer',
                    size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3 py-1.5 text-xs sm:text-sm',
                    modelValue === tab.value
                        ? 'bg-background text-foreground shadow-xs font-semibold'
                        : 'text-muted-foreground hover:text-foreground hover:bg-background/40',
                )
            "
            @click="selectTab(tab)"
            @keydown="handleKeyDown($event, index)"
        >
            <component
                v-if="tab.icon"
                :is="tab.icon"
                :stroke-width="1.75"
                class="size-3.5 shrink-0"
            />
            <span>{{ tab.label }}</span>
            <span
                v-if="tab.count !== undefined"
                :class="
                    cn(
                        'rounded-full px-1.5 py-0.2 text-[10px] font-semibold tabular-nums',
                        modelValue === tab.value
                            ? 'bg-muted text-foreground'
                            : 'bg-muted/80 text-muted-foreground',
                    )
                "
            >
                {{ tab.count }}
            </span>
        </button>
    </div>

    <!-- Underline Variant -->
    <div
        v-else
        role="tablist"
        :class="
            cn(
                'flex items-center gap-2 border-b border-border text-sm select-none',
                props.class,
            )
        "
    >
        <button
            v-for="(tab, index) in tabs"
            :key="tab.value"
            role="tab"
            type="button"
            :aria-selected="modelValue === tab.value"
            :disabled="tab.disabled"
            :class="
                cn(
                    'relative inline-flex items-center gap-2 -mb-px border-b-2 py-2.5 font-medium transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/40 disabled:pointer-events-none disabled:opacity-40 cursor-pointer',
                    size === 'sm' ? 'px-2.5 text-xs' : 'px-3.5 text-sm',
                    modelValue === tab.value
                        ? 'border-primary text-foreground font-semibold'
                        : 'border-transparent text-muted-foreground hover:text-foreground hover:border-border',
                )
            "
            @click="selectTab(tab)"
            @keydown="handleKeyDown($event, index)"
        >
            <component
                v-if="tab.icon"
                :is="tab.icon"
                :stroke-width="1.75"
                class="size-4 shrink-0"
            />
            <span>{{ tab.label }}</span>
            <span
                v-if="tab.count !== undefined"
                :class="
                    cn(
                        'rounded-full px-1.5 py-0.5 text-[10px] font-medium tabular-nums',
                        modelValue === tab.value
                            ? 'bg-primary/10 text-primary dark:bg-primary/20 dark:text-primary'
                            : 'bg-muted text-muted-foreground',
                    )
                "
            >
                {{ tab.count }}
            </span>
        </button>
    </div>
</template>
