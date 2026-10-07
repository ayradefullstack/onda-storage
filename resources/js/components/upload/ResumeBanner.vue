<script setup lang="ts">
import { TriangleAlertIcon } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatBytes, truncateFilenameMiddle } from '@/lib/format';
import type { UploadFileState } from '@/types/upload';

const props = defineProps<{
    files: UploadFileState[];
}>();

const emit = defineEmits<{
    reselect: [id: string, file: File];
}>();

const { t, locale } = useI18n();

const fileInputs = ref<Record<string, HTMLInputElement | undefined>>({});

function setInputRef(id: string, el: Element | { $el?: Element } | null): void {
    fileInputs.value[id] = el instanceof HTMLInputElement ? el : undefined;
}

function triggerPicker(id: string): void {
    fileInputs.value[id]?.click();
}

function onFileChosen(id: string, event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.item(0);

    if (file) {
        emit('reselect', id, file);
    }

    input.value = '';
}
</script>

<template>
    <Alert v-if="props.files.length > 0" class="border-amber-500/50">
        <TriangleAlertIcon class="size-4 text-amber-600" />
        <AlertTitle>{{ t('upload.resume.title') }}</AlertTitle>
        <AlertDescription>
            <p class="mb-3">{{ t('upload.resume.description') }}</p>
            <ul class="space-y-2">
                <li
                    v-for="file in props.files"
                    :key="file.id"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span class="text-sm" :title="file.filename"
                        ><bdi>{{ truncateFilenameMiddle(file.filename) }}</bdi>
                        —
                        <bdi dir="ltr">{{
                            formatBytes(file.size, locale)
                        }}</bdi></span
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="triggerPicker(file.id)"
                    >
                        {{ t('upload.resume.selectFile') }}
                    </Button>
                    <input
                        :ref="
                            (el) => setInputRef(file.id, el as Element | null)
                        "
                        type="file"
                        class="hidden"
                        @change="onFileChosen(file.id, $event)"
                    />
                </li>
            </ul>
        </AlertDescription>
    </Alert>
</template>
