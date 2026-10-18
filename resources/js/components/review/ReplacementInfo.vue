<script setup lang="ts">
import { MessageSquareWarning, RefreshCcw } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * Admin side: this file is a replacement the author uploaded during review.
 * Says which file it replaces, with that file's rejection reason one click
 * away, so the officer can check the new file answers it.
 */
defineProps<{
    replaces: { original_name: string; review_reason: string | null };
}>();

const { t } = useI18n();
</script>

<template>
    <div
        class="flex min-w-0 flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
        data-testid="replacement-info"
    >
        <Badge variant="secondary" class="gap-1">
            <RefreshCcw class="size-3" aria-hidden="true" />
            {{ t('review.replacement.badge') }}
        </Badge>
        <span class="min-w-0 truncate">
            {{ t('review.replacement.replaces') }}
            <bdi dir="ltr" class="font-medium text-foreground">{{
                replaces.original_name
            }}</bdi>
        </span>
        <Popover v-if="replaces.review_reason">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1 rounded text-xs font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/40"
                    data-testid="previous-reason-trigger"
                >
                    <MessageSquareWarning class="size-3.5" aria-hidden="true" />
                    {{ t('review.replacement.previousReason') }}
                </button>
            </PopoverTrigger>
            <PopoverContent
                class="max-w-sm text-xs"
                data-testid="previous-reason"
            >
                <p class="mb-1 font-semibold text-foreground">
                    {{ t('review.replacement.previousReason') }}
                </p>
                <p class="whitespace-pre-line text-foreground">
                    <bdi dir="auto">{{ replaces.review_reason }}</bdi>
                </p>
            </PopoverContent>
        </Popover>
    </div>
</template>
