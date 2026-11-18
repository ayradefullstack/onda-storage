<script setup lang="ts">
import { Ban } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { AppDialog } from '@/components/ui/app-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { truncateFilenameMiddle } from '@/lib/format';
import { reasonState } from './decisions';
import type { ReasonChip } from './decisions';
import RejectReasonField from './RejectReasonField.vue';

/**
 * Rejecting one file: a reason the author will read word for word (20 to
 * 2000 characters, trimmed), with file-level quick reasons inserted at the
 * cursor. The parent posts; while it does (`busy`), Escape, the close
 * button and a click outside are ignored.
 *
 * `initial` pre-fills the field when the officer is changing the reason of
 * an already rejected file.
 */
const props = defineProps<{
    open: boolean;
    fileName: string;
    busy?: boolean;
    serverError?: string | null;
    initial?: string | null;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    confirm: [reason: string];
}>();

const { t } = useI18n();

const CHIP_IDS = [
    'unreadable',
    'wrongType',
    'incomplete',
    'wrongFormat',
    'duplicate',
] as const;

const chips = computed<ReasonChip[]>(() =>
    CHIP_IDS.map((id) => {
        const label = t(`review.file.chips.${id}`);

        return { id, label, text: label };
    }),
);

const reason = ref('');
const field = ref<InstanceType<typeof RejectReasonField> | null>(null);
const valid = computed(() => reasonState(reason.value) === 'ok');

watch(
    () => props.open,
    (open) => {
        if (open) {
            reason.value = props.initial ?? '';
        }
    },
    { immediate: true },
);

function submit(): void {
    if (props.busy) {
        return;
    }

    if (!valid.value) {
        field.value?.touch();

        return;
    }

    emit('confirm', reason.value.trim());
}
</script>

<template>
    <AppDialog
        :open="open"
        size="md"
        :busy="busy"
        form
        @update:open="(value: boolean) => emit('update:open', value)"
        @submit="submit"
    >
        <template #icon>
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-destructive/20 bg-destructive/10 text-destructive"
            >
                <Ban class="size-5" />
            </div>
        </template>
        <template #title>{{ t('review.file.rejectDialog.title') }}</template>
        <template #description>
            <bdi dir="ltr" class="font-mono text-xs" :title="fileName">{{
                truncateFilenameMiddle(fileName, 48)
            }}</bdi>
        </template>

        <p class="mb-3 text-sm text-muted-foreground">
            {{ t('review.file.rejectDialog.description') }}
        </p>
        <RejectReasonField
            v-if="open"
            ref="field"
            v-model="reason"
            :chips="chips"
            :disabled="busy"
            :server-error="serverError"
        />

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
                type="submit"
                variant="destructive"
                data-testid="file-reject-confirm"
                :disabled="busy || !valid"
            >
                <Spinner v-if="busy" class="size-4" />
                <Ban v-else class="size-4" />
                {{ t('review.file.rejectDialog.confirm') }}
            </Button>
        </template>
    </AppDialog>
</template>
