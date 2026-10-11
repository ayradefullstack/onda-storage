<script setup lang="ts">
import { Loader2Icon } from '@lucide/vue';
import { computed, defineAsyncComponent, toRef } from 'vue';
import type { Component } from 'vue';
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

const emit = defineEmits<{ refresh: [] }>();

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

const status = computed(() => props.descriptor?.status);

const { gaveUp } = useConsultationPolling(status, toRef(props, 'fileKey'), () =>
    emit('refresh'),
);

const body = computed<Component | null>(() => {
    if (props.descriptor === null || props.descriptor.status !== 'ready') {
        return null;
    }

    return BODIES[props.descriptor.family] ?? null;
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
            class="flex h-full min-h-48 items-center justify-center"
        >
            <Loader2Icon class="size-8 animate-spin text-muted-foreground" />
        </div>
        <component
            :is="body"
            v-else-if="body !== null"
            :key="fileKey"
            :descriptor="descriptor"
            :compact="compact"
            @asset-expired="onAssetExpired"
        />
        <UnsupportedCard
            v-else
            :descriptor="descriptor"
            :compact="compact"
            :gave-up="gaveUp"
        />
    </div>
</template>
