<script setup lang="ts">
import { PauseIcon, PlayIcon, RotateCcwIcon, Trash2Icon } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import ProgressRing from '@/components/upload/ProgressRing.vue';
import { formatBytes, formatDuration, formatSpeed } from '@/lib/format';
import type { UploadFileState } from '@/types/upload';

const props = defineProps<{
    file: UploadFileState;
}>();

const emit = defineEmits<{
    pause: [id: string];
    resume: [id: string];
    cancel: [id: string];
}>();

const { t, locale } = useI18n();

const percent = computed(() =>
    props.file.size > 0
        ? (props.file.bytesUploaded / props.file.size) * 100
        : 0,
);
const canPause = computed(
    () =>
        props.file.status === 'uploading' ||
        props.file.status === 'initializing',
);
const canResume = computed(
    () =>
        (props.file.status === 'paused' || props.file.status === 'failed') &&
        props.file.file !== null,
);
const canCancel = computed(() => props.file.status !== 'completed');

const errorMessage = computed(() => {
    if (props.file.status === 'quota_exceeded') {
        return props.file.remainingQuotaBytes !== null
            ? t('upload.error.quotaExceededWithRemaining', {
                  remaining: formatBytes(
                      props.file.remainingQuotaBytes,
                      locale.value,
                  ),
              })
            : t('upload.error.quotaExceeded');
    }

    if (props.file.status === 'expired') {
        return t('upload.error.expired');
    }

    if (props.file.status === 'failed' && props.file.errorCode) {
        return props.file.errorChunkIndex !== null
            ? t(`upload.error.${props.file.errorCode}WithChunk`, {
                  index: props.file.errorChunkIndex,
              })
            : t(`upload.error.${props.file.errorCode}`);
    }

    return null;
});
</script>

<template>
    <div class="flex items-center gap-4 rounded-lg border p-3">
        <ProgressRing :percent="percent" :size="40" :stroke-width="4" />

        <div class="min-w-0 flex-1">
            <div class="flex items-center justify-between gap-2">
                <p class="truncate text-sm font-medium" :title="file.filename">
                    {{ file.filename }}
                </p>
                <span class="shrink-0 text-xs text-muted-foreground">{{
                    t(`upload.status.${file.status}`)
                }}</span>
            </div>

            <div
                class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground"
            >
                <span
                    >{{ formatBytes(file.bytesUploaded, locale) }} /
                    {{ formatBytes(file.size, locale) }}</span
                >
                <span v-if="file.status === 'uploading'">{{
                    formatSpeed(file.speedBps, locale)
                }}</span>
                <span
                    v-if="
                        file.status === 'uploading' && file.etaSeconds !== null
                    "
                >
                    {{
                        t('upload.eta', {
                            time: formatDuration(file.etaSeconds),
                        })
                    }}
                </span>
            </div>

            <p
                v-if="errorMessage"
                class="mt-1 text-xs text-destructive"
                role="alert"
            >
                {{ errorMessage }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-1">
            <Button
                v-if="canPause"
                variant="ghost"
                size="icon-sm"
                :aria-label="t('upload.actions.pause')"
                @click="emit('pause', file.id)"
            >
                <PauseIcon />
            </Button>
            <Button
                v-if="canResume"
                variant="ghost"
                size="icon-sm"
                :aria-label="t('upload.actions.resume')"
                @click="emit('resume', file.id)"
            >
                <PlayIcon v-if="file.status === 'paused'" />
                <RotateCcwIcon v-else />
            </Button>
            <Button
                v-if="canCancel"
                variant="ghost"
                size="icon-sm"
                :aria-label="t('upload.actions.cancel')"
                @click="emit('cancel', file.id)"
            >
                <Trash2Icon />
            </Button>
        </div>
    </div>
</template>
