<script setup lang="ts">
import { AlertCircle, CloudUpload, UploadCloud } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import type { RequirementSlot } from '@/components/upload/requirement';
import {
    acceptAttribute,
    formatExtensions,
    sizeLimitBytes,
    toUploadRequirement,
} from '@/components/upload/requirement';
import { useUploadQueue } from '@/composables/useUploadQueue';
import type { FileRejection } from '@/composables/useUploadQueue';
import { formatBytes, truncateFilenameMiddle } from '@/lib/format';
import {
    allowedExtensionList,
    MAX_FILE_SIZE_BYTES,
} from '@/lib/uploadValidation';

const props = defineProps<{
    oeuvreId: number;
    requirement?: RequirementSlot | null;
    compact?: boolean;
    disabled?: boolean;
}>();

const { t, locale } = useI18n();
const { selectFiles } = useUploadQueue();

const isDragging = ref(false);
const dragDepth = ref(0);
const fileInput = ref<HTMLInputElement | null>(null);
const rejections = ref<FileRejection[]>([]);

const extensionsLabel = computed(() =>
    props.requirement
        ? formatExtensions(props.requirement.extensions)
        : allowedExtensionList().join(', '),
);
const sizeLimit = computed(() =>
    props.requirement ? sizeLimitBytes(props.requirement) : MAX_FILE_SIZE_BYTES,
);
const accept = computed(() =>
    props.requirement
        ? acceptAttribute(props.requirement.extensions)
        : undefined,
);
const multiple = computed(() => props.requirement?.allows_multiple ?? true);

function handleFiles(fileList: FileList | null): void {
    if (!fileList || fileList.length === 0 || props.disabled) {
        return;
    }

    const files = multiple.value
        ? Array.from(fileList)
        : Array.from(fileList).slice(0, 1);

    rejections.value = selectFiles(
        files,
        props.oeuvreId,
        props.requirement ? toUploadRequirement(props.requirement) : null,
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
            class="group relative flex flex-col items-center justify-center rounded-2xl border-2 border-dashed text-center transition-all duration-200 select-none cursor-pointer"
            :class="[
                compact
                    ? 'gap-3 p-4 sm:flex-row sm:justify-between sm:text-start'
                    : 'gap-4 p-8 sm:p-10',
                isDragging
                    ? 'border-onda-blue-500 bg-onda-blue-500/10 shadow-lg shadow-onda-blue-500/10 scale-[1.008]'
                    : 'border-border/80 bg-muted/20 hover:border-onda-blue-500/50 hover:bg-muted/35',
                disabled ? 'pointer-events-none opacity-40 cursor-not-allowed' : '',
            ]"
            @dragover.prevent
            @dragenter.prevent="onDragEnter"
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onDrop"
            @click="onBrowse"
        >
            <div class="flex items-center gap-3">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-onda-blue-500/10 text-onda-blue-600 dark:text-onda-blue-400 transition-transform duration-200 group-hover:scale-105"
                    :class="{ 'animate-bounce': isDragging }"
                >
                    <CloudUpload class="size-5" />
                </div>

                <div class="space-y-0.5">
                    <p class="text-xs sm:text-sm font-semibold tracking-tight text-foreground">
                        {{ t('upload.dropzone.title') }}
                    </p>
                    <i18n-t
                        keypath="upload.dropzone.hint"
                        tag="p"
                        class="text-[11px] text-muted-foreground"
                    >
                        <template #size>
                            <bdi dir="ltr" class="font-mono">{{ formatBytes(sizeLimit, locale) }}</bdi>
                        </template>
                        <template #extensions>
                            <bdi dir="ltr" class="font-mono text-foreground/80 font-medium">{{ extensionsLabel }}</bdi>
                        </template>
                    </i18n-t>
                </div>
            </div>

            <Button
                type="button"
                variant="outline"
                size="sm"
                class="shrink-0 h-8 px-3 text-xs font-semibold cursor-pointer border-border hover:border-onda-blue-500 hover:bg-onda-blue-500/10"
                :disabled="disabled"
                @click.stop="onBrowse"
            >
                {{ t('upload.dropzone.browse') }}
            </Button>

            <input
                ref="fileInput"
                type="file"
                :multiple="multiple"
                :accept="accept"
                class="hidden"
                @change="onInputChange"
            />
        </div>

        <!-- Rejections Notice -->
        <ul v-if="rejections.length > 0" class="mt-2.5 space-y-1.5">
            <li
                v-for="(rejection, index) in rejections"
                :key="index"
                class="flex items-start gap-2 rounded-lg border border-destructive/25 bg-destructive/10 p-2.5 text-xs text-destructive"
            >
                <AlertCircle class="mt-0.5 size-4 shrink-0" />
                <div class="flex-1">
                    <i18n-t
                        v-if="rejection.reason === 'extension'"
                        keypath="upload.reject.extension"
                    >
                        <template #filename>
                            <bdi class="font-semibold">{{ truncateFilenameMiddle(rejection.filename) }}</bdi>
                        </template>
                        <template #extensions>
                            <bdi dir="ltr" class="font-mono">{{ extensionsLabel }}</bdi>
                        </template>
                    </i18n-t>
                    <i18n-t v-else keypath="upload.reject.size">
                        <template #filename>
                            <bdi class="font-semibold">{{ truncateFilenameMiddle(rejection.filename) }}</bdi>
                        </template>
                        <template #size>
                            <bdi dir="ltr" class="font-mono">{{ formatBytes(sizeLimit, locale) }}</bdi>
                        </template>
                    </i18n-t>
                </div>
            </li>
        </ul>
    </div>
</template>
