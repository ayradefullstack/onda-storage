<script setup lang="ts">
import { AlertTriangleIcon, Loader2Icon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { truncateFilenameMiddle } from '@/lib/format';
import { link } from '@/routes/media';
import type { MediaFileSummary } from '@/types/upload';

const props = defineProps<{
    open: boolean;
    mediaFile: MediaFileSummary | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { t } = useI18n();

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

const streamUrl = ref<string | null>(null);
const loadState = ref<LoadState>('idle');

/**
 * Only formats this feature actually decrypts a preview for. Anything
 * else (pptx, for now) gets an honest "not available" message rather than
 * a broken embed — full-download is a separate, later phase.
 */
const previewKind = computed<
    'image' | 'video' | 'audio' | 'pdf' | 'unsupported'
>(() => {
    const mime = props.mediaFile?.mime ?? '';

    if (mime.startsWith('image/')) {
        return 'image';
    }

    if (mime.startsWith('video/')) {
        return 'video';
    }

    if (mime.startsWith('audio/')) {
        return 'audio';
    }

    if (mime === 'application/pdf') {
        return 'pdf';
    }

    return 'unsupported';
});

async function loadStreamUrl(): Promise<void> {
    if (!props.mediaFile) {
        return;
    }

    loadState.value = 'loading';
    streamUrl.value = null;

    try {
        // Issued fresh every time the dialog opens — the signed link is
        // only valid 15 minutes, and there's no reason to hold one longer
        // than the moment it's actually used.
        const response = await fetch(
            link({ mediaFile: props.mediaFile.uuid }).url,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const body = (await response.json()) as { url: string };
        streamUrl.value = body.url;
        loadState.value = 'ready';
    } catch {
        loadState.value = 'error';
    }
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            void loadStreamUrl();
        } else {
            streamUrl.value = null;
            loadState.value = 'idle';
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-w-3xl">
            <DialogHeader>
                <DialogTitle class="truncate" :title="mediaFile?.original_name">
                    <bdi>{{
                        mediaFile
                            ? truncateFilenameMiddle(
                                  mediaFile.original_name,
                                  60,
                              )
                            : ''
                    }}</bdi>
                </DialogTitle>
            </DialogHeader>

            <div class="flex min-h-[300px] items-center justify-center">
                <Loader2Icon
                    v-if="loadState === 'loading'"
                    class="size-8 animate-spin text-muted-foreground"
                />

                <div
                    v-else-if="loadState === 'error'"
                    class="flex flex-col items-center gap-2 text-center text-sm text-muted-foreground"
                >
                    <AlertTriangleIcon class="size-6" />
                    <p>{{ t('media.preview.error') }}</p>
                </div>

                <template v-else-if="loadState === 'ready' && streamUrl">
                    <img
                        v-if="previewKind === 'image'"
                        :src="streamUrl"
                        :alt="mediaFile?.original_name"
                        class="max-h-[70vh] w-full rounded-md object-contain"
                    />
                    <video
                        v-else-if="previewKind === 'video'"
                        :src="streamUrl"
                        controls
                        class="max-h-[70vh] w-full rounded-md"
                    />
                    <audio
                        v-else-if="previewKind === 'audio'"
                        :src="streamUrl"
                        controls
                        class="w-full"
                    />
                    <iframe
                        v-else-if="previewKind === 'pdf'"
                        :src="streamUrl"
                        :title="mediaFile?.original_name"
                        class="h-[70vh] w-full rounded-md border"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('media.preview.unsupported') }}
                    </p>
                </template>
            </div>
        </DialogContent>
    </Dialog>
</template>
