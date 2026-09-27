<script setup lang="ts">
import type { Component } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Inbox } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface Props {
    title: string;
    description?: string;
    icon?: Component;
    actionText?: string;
    actionHref?: string;
    class?: string;
}

withDefaults(defineProps<Props>(), {
    icon: Inbox,
});

const emit = defineEmits<{
    (e: 'action'): void;
}>();
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col items-center justify-center rounded-xl border border-dashed border-border/80 bg-card/30 px-6 py-12 text-center select-none',
                $props.class,
            )
        "
    >
        <div
            class="mb-3 flex size-12 items-center justify-center rounded-xl bg-muted/60 text-muted-foreground"
        >
            <component :is="icon" :stroke-width="1.75" class="size-6 text-muted-foreground/80" />
        </div>

        <h3 class="text-sm font-semibold text-foreground">
            {{ title }}
        </h3>

        <p
            v-if="description"
            class="mt-1 max-w-sm text-xs leading-relaxed text-muted-foreground"
        >
            {{ description }}
        </p>

        <div v-if="$slots.default || actionText" class="mt-4 flex flex-wrap items-center justify-center gap-2">
            <slot>
                <Button
                    v-if="actionText && actionHref"
                    as-child
                    size="sm"
                    variant="default"
                >
                    <Link :href="actionHref">
                        {{ actionText }}
                    </Link>
                </Button>
                <Button
                    v-else-if="actionText"
                    size="sm"
                    variant="outline"
                    @click="emit('action')"
                >
                    {{ actionText }}
                </Button>
            </slot>
        </div>
    </div>
</template>
