<script setup lang="ts">
import { UploadCloudIcon } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { useUploadQueue } from '@/composables/useUploadQueue';
import { formatBytes } from '@/lib/format';
import {
    allowedExtensionList,
    MAX_FILE_SIZE_BYTES,
} from '@/lib/uploadValidation';

const props = defineProps<{
    workId: number;
}>();

const { t, locale } = useI18n();
const { selectFiles } = useUploadQueue();

const isDragging = ref(false);
const dragDepth = ref(0);
const fileInput = ref<HTMLInputElement | null>(null);
const rejectionMessages = ref<string[]>([]);

function handleFiles(fileList: FileList | null): void {
    if (!fileList || fileList.length === 0) {
        return;
    }

    const rejections = selectFiles(fileList, props.workId);
    rejectionMessages.value = rejections.map((rejection) =>
        rejection.reason === 'extension'
            ? t('upload.reject.extension', { filename: rejection.filename })
            : t('upload.reject.size', { filename: rejection.filename }),
    );
}

function onDragEnter(): void {
    dragDepth.value++;
    isDragging.value = true;
}

function onDragLeave(): void {
    dragDepth.value = Math.max(0, dragDepth.value - 1);

    if (dragDepth.value === 0) {
        isDragging.value = false;
    }
}

function onDrop(event: DragEvent): void {
    dragDepth.value = 0;
    isDragging.value = false;
    handleFiles(event.dataTransfer?.files ?? null);
}

function onBrowse(): void {
    fileInput.value?.click();
}

function onInputChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    handleFiles(input.files);
    input.value = '';
}
</script>

<template>
    <div>
        <div
            class="flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed p-10 text-center transition-colors"
            :class="isDragging ? 'border-primary bg-primary/5' : 'border-input'"
            @dragover.prevent
            @dragenter.prevent="onDragEnter"
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onDrop"
        >
            <UploadCloudIcon class="size-8 text-muted-foreground" />
            <div>
                <p class="text-sm font-medium">
                    {{ t('upload.dropzone.title') }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        t('upload.dropzone.hint', {
                            size: formatBytes(MAX_FILE_SIZE_BYTES, locale),
                            extensions: allowedExtensionList().join(', '),
                        })
                    }}
                </p>
            </div>
            <Button type="button" variant="outline" size="sm" @click="onBrowse">
                {{ t('upload.dropzone.browse') }}
            </Button>
            <input
                ref="fileInput"
                type="file"
                multiple
                class="hidden"
                @change="onInputChange"
            />
        </div>

        <ul v-if="rejectionMessages.length > 0" class="mt-2 space-y-1">
            <li
                v-for="(message, index) in rejectionMessages"
                :key="index"
                class="text-xs text-destructive"
            >
                {{ message }}
            </li>
        </ul>
    </div>
</template>
