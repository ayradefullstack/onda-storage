<script setup lang="ts">
import { computed, defineAsyncComponent, toRef } from 'vue';
import type { Component } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import type {
    ConsultationDescriptor,
    PreviewFamily,
} from '@/types/consultation';
import { useConsultationPolling } from './useConsultationPolling';
import UnsupportedCard from './viewers/UnsupportedCard.vue';

/**
 * THE viewer — the same component backs the full review page and the side
 * Viewer on the oeuvre page; `compact` only trims chrome. It owns the shell
 * (states, polling of a pending derivative, expired-URL recovery) and picks
 * the body from `family`. It never looks at a filename or extension: the
 * server's `family` is the only input.
 *
 * Each body is a separate async chunk, so the PDF page viewer is not
 * downloaded to play an audio file. Every body takes the same props and emits
 * the same `asset-expired`, which becomes one debounced `refresh` the parent
 * answers with fresh descriptors.
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor | null;
    /** Identity of the file shown; restarts polling when it changes. */
    fileKey: string;
    compact?: boolean;
}>();

const emit = defineEmits<{
    refresh: [];
    /** 0..1 reading position, from the bodies that scroll a document. */
    progress: [ratio: number];
}>();

const BODIES: Partial<Record<PreviewFamily, Component>> = {
    pdf: defineAsyncComponent(() => import('./viewers/PageImagesViewer.vue')),
    document: defineAsyncComponent(
        () => import('./viewers/PageImagesViewer.vue'),
    ),
    presentation: defineAsyncComponent(
        () => import('./viewers/PageImagesViewer.vue'),
    ),
    spreadsheet: defineAsyncComponent(
        () => import('./viewers/SheetViewer.vue'),
    ),
    csv: defineAsyncComponent(() => import('./viewers/SheetViewer.vue')),
    image: defineAsyncComponent(() => import('./viewers/ImageViewer.vue')),
    video: defineAsyncComponent(() => import('./viewers/VideoViewer.vue')),
    audio: defineAsyncComponent(() => import('./viewers/AudioViewer.vue')),
    text: defineAsyncComponent(() => import('./viewers/TextViewer.vue')),
};

/** Bodies for a file the browser renders itself from the decrypted original. */
const ORIGINAL_BODIES: Partial<Record<PreviewFamily, Component>> = {
    pdf: defineAsyncComponent(() => import('./viewers/PdfViewer.vue')),
    document: defineAsyncComponent(() => import('./viewers/DocxViewer.vue')),
    video: BODIES.video,
    audio: BODIES.audio,
};

const OUTLINE_BODY = defineAsyncComponent(
    () => import('./viewers/PptxOutlineViewer.vue'),
);

const status = computed(() => props.descriptor?.status);

const { gaveUp } = useConsultationPolling(status, toRef(props, 'fileKey'), () =>
    emit('refresh'),
);

const body = computed<Component | null>(() => {
    if (props.descriptor === null || props.descriptor.status !== 'ready') {
        return null;
    }

    const { family, mode, assets, original } = props.descriptor;

    if (mode === 'original_inline') {
        return original === null ? null : (ORIGINAL_BODIES[family] ?? null);
    }

    if (family === 'presentation' && assets.some((a) => a.kind === 'outline')) {
        return OUTLINE_BODY;
    }

    return BODIES[family] ?? null;
});

let refreshTimer: ReturnType<typeof setTimeout> | null = null;

/** A page of 40 images can all fail at once when a URL expires: one refresh. */
function onAssetExpired(): void {
    if (refreshTimer !== null) {
        return;
    }

    refreshTimer = setTimeout(() => {
        refreshTimer = null;
        emit('refresh');
    }, 500);
}
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col overflow-hidden rounded-lg border bg-background"
        :class="compact ? 'min-h-72' : ''"
        :data-family="descriptor?.family"
        :data-status="descriptor?.status ?? 'loading'"
        data-testid="consult-viewer"
    >
        <div
            v-if="descriptor === null"
            class="flex h-full min-h-48 flex-col gap-3 p-4"
            role="status"
            aria-busy="true"
            data-testid="viewer-skeleton"
        >
            <Skeleton class="h-8 w-2/3" />
            <Skeleton class="min-h-0 flex-1" />
        </div>
        <component
            :is="body"
            v-else-if="body !== null"
            :key="fileKey"
            :descriptor="descriptor"
            :compact="compact"
            @asset-expired="onAssetExpired"
            @scroll-progress="(ratio: number) => emit('progress', ratio)"
        />
        <UnsupportedCard
            v-else
            :descriptor="descriptor"
            :compact="compact"
            :gave-up="gaveUp"
        />
    </div>
</template>
