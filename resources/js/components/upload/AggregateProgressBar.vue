<script setup lang="ts">
import { ChevronUpIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import DepositCard from '@/components/upload/DepositCard.vue';
import type { UploadEntry } from '@/components/upload/depositJourney';
import ResumeBanner from '@/components/upload/ResumeBanner.vue';
import { useUploadQueue } from '@/composables/useUploadQueue';

const { t } = useI18n();
const {
    files,
    pendingResumes,
    pauseFile,
    resumeFile,
    cancelFile,
    resumeWithReselectedFile,
} = useUploadQueue();

const isOpen = ref(false);

const activeFiles = computed(() =>
    files.value.filter((f) => f.status !== 'completed'),
);

const aggregatePercent = computed(() => {
    const relevant = files.value.filter(
        (f) => f.status !== 'completed' && f.size > 0,
    );

    if (relevant.length === 0) {
        return 100;
    }

    const totalSize = relevant.reduce((sum, f) => sum + f.size, 0);
    const totalUploaded = relevant.reduce((sum, f) => sum + f.bytesUploaded, 0);

    return totalSize > 0 ? Math.round((totalUploaded / totalSize) * 100) : 0;
});

function toEntry(file: (typeof files.value)[number]): UploadEntry {
    return { kind: 'upload', file };
}

function onReselect(id: string, file: File): void {
    const result = resumeWithReselectedFile(id, file);

    if (!result.ok) {
        toast.error(t('upload.resume.sizeMismatch'));
    }
}
</script>

<template>
    <div
        v-if="activeFiles.length > 0"
        class="fixed inset-x-0 bottom-0 z-50 border-t bg-background/95 shadow-lg backdrop-blur"
    >
        <button
            type="button"
            class="flex w-full items-center justify-between gap-4 px-4 py-2 text-start"
            :aria-expanded="isOpen"
            @click="isOpen = true"
        >
            <span class="text-sm font-medium">
                {{
                    t('upload.aggregate.inProgress', {
                        count: activeFiles.length,
                    })
                }}
            </span>
            <span class="flex items-center gap-2">
                <span class="h-1.5 w-32 overflow-hidden rounded-full bg-muted">
                    <span
                        class="block h-full rounded-full bg-primary transition-[width] duration-300"
                        :style="{ width: `${aggregatePercent}%` }"
                    />
                </span>
                <span class="text-xs text-muted-foreground tabular-nums"
                    >{{ aggregatePercent }}%</span
                >
                <ChevronUpIcon class="size-4 text-muted-foreground" />
            </span>
        </button>
    </div>

    <Sheet v-model:open="isOpen">
        <SheetContent side="bottom" class="max-h-[70vh] overflow-y-auto">
            <SheetHeader>
                <SheetTitle>{{ t('upload.aggregate.title') }}</SheetTitle>
            </SheetHeader>

            <div class="space-y-3 px-4 pb-6">
                <ResumeBanner :files="pendingResumes" @reselect="onReselect" />

                <DepositCard
                    v-for="file in activeFiles"
                    :key="file.id"
                    :entry="toEntry(file)"
                    @pause="pauseFile"
                    @resume="resumeFile"
                    @cancel="cancelFile"
                />

                <Button
                    v-if="activeFiles.length === 0"
                    variant="ghost"
                    disabled
                    class="w-full"
                >
                    {{ t('upload.aggregate.empty') }}
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
