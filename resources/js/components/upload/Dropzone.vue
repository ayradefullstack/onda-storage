<script setup lang="ts">
import { LockIcon, ShieldCheckIcon, UploadCloudIcon } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { useUploadQueue } from '@/composables/useUploadQueue';
import type { FileRejection } from '@/composables/useUploadQueue';
import { formatBytes, truncateFilenameMiddle } from '@/lib/format';
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
const rejections = ref<FileRejection[]>([]);

function handleFiles(fileList: FileList | null): void {
    if (!fileList || fileList.length === 0) {
        return;
    }

    rejections.value = selectFiles(fileList, props.workId);
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
                <i18n-t
                    keypath="upload.dropzone.hint"
                    tag="p"
                    class="text-xs text-muted-foreground"
                >
                    <template #size
                        ><bdi dir="ltr">{{
                            formatBytes(MAX_FILE_SIZE_BYTES, locale)
                        }}</bdi></template
                    >
                    <template #extensions
                        ><bdi dir="ltr">{{
                            allowedExtensionList().join(', ')
                        }}</bdi></template
                    >
                </i18n-t>
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

        <ul v-if="rejections.length > 0" class="mt-2 space-y-1">
            <li
                v-for="(rejection, index) in rejections"
                :key="index"
                class="text-xs text-destructive"
            >
                <i18n-t
                    v-if="rejection.reason === 'extension'"
                    keypath="upload.reject.extension"
                >
                    <template #filename
                        ><bdi>{{
                            truncateFilenameMiddle(rejection.filename)
                        }}</bdi></template
                    >
                    <template #extensions
                        ><bdi dir="ltr">{{
                            allowedExtensionList().join(', ')
                        }}</bdi></template
                    >
                </i18n-t>
                <i18n-t v-else keypath="upload.reject.size">
                    <template #filename
                        ><bdi>{{
                            truncateFilenameMiddle(rejection.filename)
                        }}</bdi></template
                    >
                    <template #size
                        ><bdi dir="ltr">{{
                            formatBytes(MAX_FILE_SIZE_BYTES, locale)
                        }}</bdi></template
                    >
                </i18n-t>
            </li>
        </ul>

        <div class="mt-3 space-y-1 text-xs text-muted-foreground">
            <p class="flex items-center gap-1.5">
                <LockIcon class="size-3.5 shrink-0" />
                {{ t('upload.trust.encrypted') }}
            </p>
            <p class="flex items-center gap-1.5">
                <ShieldCheckIcon class="size-3.5 shrink-0" />
                {{ t('upload.trust.fingerprint') }}
            </p>
            <p class="flex items-center gap-1.5">
                <ShieldCheckIcon class="size-3.5 shrink-0" />
                {{ t('upload.trust.audit') }}
            </p>
        </div>
    </div>
</template>
