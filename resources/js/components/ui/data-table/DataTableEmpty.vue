<script setup lang="ts">
import type { Component } from 'vue';
import { SearchX } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface Props {
    title?: string;
    description?: string;
    icon?: Component;
    actionText?: string;
    class?: string;
}

withDefaults(defineProps<Props>(), {
    icon: SearchX,
});

const emit = defineEmits<{
    (e: 'clear'): void;
}>();

const { t } = useI18n();
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col items-center justify-center p-8 text-center sm:p-14 select-none',
                $props.class,
            )
        "
    >
        <div
            class="mb-3.5 flex size-12 items-center justify-center rounded-xl bg-muted/60 text-muted-foreground"
        >
            <component :is="icon" :stroke-width="1.75" class="size-6 text-muted-foreground/80" />
        </div>

        <h3 class="text-sm font-semibold text-foreground">
            {{ title || t('common.noResults', 'Aucun résultat trouvé') }}
        </h3>

        <p class="mt-1 max-w-sm text-xs leading-relaxed text-muted-foreground">
            {{ description || t('common.noResultsDescription', 'Aucun enregistrement ne correspond à vos filtres actuels.') }}
        </p>

        <div class="mt-4 flex items-center justify-center gap-2">
            <Button
                variant="outline"
                size="sm"
                class="h-8 gap-1.5 rounded-lg text-xs font-medium cursor-pointer"
                @click="emit('clear')"
            >
                {{ actionText || t('common.clearFilters', 'Effacer les filtres') }}
            </Button>
        </div>
    </div>
</template>
