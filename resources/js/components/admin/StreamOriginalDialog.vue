<script setup lang="ts">
import { AlertTriangleIcon, Loader2Icon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { truncateFilenameMiddle } from '@/lib/format';
import { link } from '@/routes/media';

interface StreamableFile {
    uuid: string;
    original_name: string;
    mime: string;
}

const props = defineProps<{
    open: boolean;
    file: StreamableFile | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { t } = useI18n();

type LoadState = 'confirm' | 'loading' | 'ready' | 'error';

const streamUrl = ref<string | null>(null);
const loadState = ref<LoadState>('confirm');

/**
 * This is the vault-scale original, unlike the small watermarked variants
 * elsewhere on this page — fetched and decrypted only once an officer
 * explicitly confirms, never on dialog open, per the admin-console task's
 * "never automatically, never on page load" rule.
 */
const previewKind = computed<
    'image' | 'video' | 'audio' | 'pdf' | 'unsupported'
>(() => {
    const mime = props.file?.mime ?? '';

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

async function confirmAndLoad(): Promise<void> {
    if (!props.file) {
        return;
    }

    loadState.value = 'loading';
    streamUrl.value = null;

    try {
        const response = await fetch(link({ mediaFile: props.file.uuid }).url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

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
        if (!isOpen) {
            streamUrl.value = null;
            loadState.value = 'confirm';
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-w-3xl">
            <DialogHeader>
                <DialogTitle class="truncate" :title="file?.original_name">
                    <bdi>{{
                        file
                            ? truncateFilenameMiddle(file.original_name, 60)
                            : ''
                    }}</bdi>
                </DialogTitle>
            </DialogHeader>

            <div v-if="loadState === 'confirm'" class="space-y-4 py-2 text-sm">
                <p class="text-muted-foreground">
                    {{ t('admin.stream.confirmBody') }}
                </p>
                <DialogFooter>
                    <Button
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        {{ t('admin.stream.cancel') }}
                    </Button>
                    <Button @click="confirmAndLoad">{{
                        t('admin.stream.confirmAction')
                    }}</Button>
                </DialogFooter>
            </div>

            <div v-else class="flex min-h-[300px] items-center justify-center">
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
                        :alt="file?.original_name"
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
                        :title="file?.original_name"
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
