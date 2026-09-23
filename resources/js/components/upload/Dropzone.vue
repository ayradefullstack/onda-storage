<script setup lang="ts">
import { LockIcon, ShieldCheckIcon, UploadCloudIcon } from '@lucide/vue';
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
    /**
     * Uploads go into this required-document slot and are validated against
     * its own formats and size cap. Omitted: the unclassified oeuvre's
     * single dropzone, against the global whitelist — unchanged.
     */
    requirement?: RequirementSlot | null;
    /** Tighter layout for a slot card; the trust lines are shown once by the page. */
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
            class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed text-center transition-colors"
            :class="[
                compact
                    ? 'gap-2 p-4 sm:flex-row sm:justify-between sm:text-start'
                    : 'gap-3 p-10',
                isDragging ? 'border-primary bg-primary/5' : 'border-input',
                disabled ? 'pointer-events-none opacity-50' : '',
            ]"
            @dragover.prevent
            @dragenter.prevent="onDragEnter"
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onDrop"
        >
            <UploadCloudIcon
                v-if="!compact"
                class="size-8 text-muted-foreground"
            />
            <div>
                <p
                    :class="
                        compact
                            ? 'text-xs text-muted-foreground'
                            : 'text-sm font-medium'
                    "
                >
                    {{ t('upload.dropzone.title') }}
                </p>
                <i18n-t
                    v-if="!compact"
                    keypath="upload.dropzone.hint"
                    tag="p"
                    class="text-xs text-muted-foreground"
                >
                    <template #size
                        ><bdi dir="ltr">{{
                            formatBytes(sizeLimit, locale)
                        }}</bdi></template
                    >
                    <template #extensions
                        ><bdi dir="ltr">{{ extensionsLabel }}</bdi></template
                    >
                </i18n-t>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="disabled"
                @click="onBrowse"
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
                        ><bdi dir="ltr">{{ extensionsLabel }}</bdi></template
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
                            formatBytes(sizeLimit, locale)
                        }}</bdi></template
                    >
                </i18n-t>
            </li>
        </ul>

        <div
            v-if="!compact"
            class="mt-3 space-y-1 text-xs text-muted-foreground"
        >
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
