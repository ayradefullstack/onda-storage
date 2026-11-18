<script setup lang="ts">
import { Clock, RefreshCw } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import type { PollPhase } from '@/composables/useFileStatusPoll';

/**
 * Says so when the server pipeline takes longer than usual — the status
 * poll keeps running underneath either way. After 3 minutes a hint; after
 * 15, a refresh button as well.
 */
defineProps<{ phase: PollPhase }>();

const emit = defineEmits<{ refresh: [] }>();

const { t } = useI18n();
</script>

<template>
    <div
        v-if="phase === 'slow' || phase === 'stalled'"
        role="status"
        aria-live="polite"
        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-xs"
        data-testid="poll-slow"
        :data-phase="phase"
    >
        <span class="flex items-center gap-2 text-foreground">
            <Clock class="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            {{
                phase === 'stalled'
                    ? t('upload.poll.stalled')
                    : t('upload.poll.slow')
            }}
        </span>
        <Button
            v-if="phase === 'stalled'"
            size="sm"
            variant="outline"
            class="h-7 cursor-pointer gap-1.5 text-xs font-semibold"
            data-testid="poll-refresh"
            @click="emit('refresh')"
        >
            <RefreshCw class="size-3" />
            {{ t('upload.poll.refresh') }}
        </Button>
    </div>
</template>
