<script setup lang="ts">
import { RefreshCcw, Trash2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { AppDialog } from '@/components/ui/app-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { truncateFilenameMiddle } from '@/lib/format';

/**
 * The one confirmation for removing a file during review, in four
 * wordings:
 *  - `replace`  a rejected file, then its slot's dropzone gets the focus;
 *  - `remove`   a rejected file; the slot stays open;
 *  - `withdraw` the author's own replacement, not decided yet;
 *  - `retry`    a replacement whose processing failed, to upload again.
 * Shows the file name and, when there is one, the ONDA's reason. The parent
 * runs the request; while it does (`busy`) Escape and outside clicks are
 * blocked and the confirm button spins.
 */
export type RemovalMode = 'replace' | 'remove' | 'withdraw' | 'retry';

defineProps<{
    open: boolean;
    mode: RemovalMode;
    fileName: string;
    reason?: string | null;
    busy?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    confirm: [];
}>();

const { t } = useI18n();
</script>

<template>
    <AppDialog
        :open="open"
        size="sm"
        :busy="busy"
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <template #title>{{ t(`oeuvres.removal.${mode}.title`) }}</template>
        <div class="space-y-3 text-sm" data-testid="removal-dialog">
            <p class="font-medium text-foreground">
                <bdi dir="ltr" class="font-mono text-xs" :title="fileName">{{
                    truncateFilenameMiddle(fileName, 48)
                }}</bdi>
            </p>
            <p
                v-if="reason"
                class="line-clamp-3 rounded border-s-2 border-destructive/40 bg-muted/60 px-2 py-1 text-xs text-foreground"
                data-testid="removal-reason"
            >
                <bdi dir="auto">{{ reason }}</bdi>
            </p>
            <p class="text-muted-foreground" data-testid="removal-body">
                {{ t(`oeuvres.removal.${mode}.body`) }}
            </p>
        </div>
        <template #footer>
            <Button
                type="button"
                variant="outline"
                :disabled="busy"
                @click="emit('update:open', false)"
            >
                {{ t('review.cancel') }}
            </Button>
            <Button
                type="button"
                variant="destructive"
                :disabled="busy"
                data-testid="removal-confirm"
                @click="!busy && emit('confirm')"
            >
                <Spinner v-if="busy" class="size-4" />
                <RefreshCcw
                    v-else-if="mode === 'replace' || mode === 'retry'"
                    class="size-4"
                />
                <Trash2 v-else class="size-4" />
                {{ t(`oeuvres.removal.${mode}.confirm`) }}
            </Button>
        </template>
    </AppDialog>
</template>
