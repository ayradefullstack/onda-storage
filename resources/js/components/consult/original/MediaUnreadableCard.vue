<script setup lang="ts">
import { FileVideoCamera } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

/** Shown when the browser cannot decode the file. Never offers a download. */
defineProps<{ kind: 'video' | 'audio'; mime: string }>();

const { t } = useI18n();
</script>

<template>
    <div
        class="flex h-full min-h-48 flex-col items-center justify-center gap-3 p-6 text-center"
        data-testid="media-unreadable"
    >
        <FileVideoCamera class="size-8 text-muted-foreground" />
        <p class="text-sm font-medium">
            {{
                kind === 'video'
                    ? t('consult.original.videoUnreadable')
                    : t('consult.original.audioUnreadable')
            }}
        </p>
        <p class="max-w-sm text-sm text-muted-foreground">
            {{ t('consult.original.codecHint') }}
            <bdi dir="ltr" class="font-mono">{{ mime }}</bdi>
        </p>
    </div>
</template>
