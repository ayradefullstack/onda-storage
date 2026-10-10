<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ConsultationDescriptor } from '@/types/consultation';
import Watermark from '../Watermark.vue';

/**
 * The 480p derivative in a plain <video>: no native download or
 * picture-in-picture, no context menu. When the signed URL expires mid-play
 * (a 403 error) the position is remembered, fresh descriptors are requested
 * through `asset-expired`, and playback resumes from the same second.
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{ 'asset-expired': [] }>();

const player = ref<HTMLVideoElement | null>(null);
let resumeAt = 0;

const video = computed(
    () =>
        props.descriptor.assets.find((asset) => asset.kind === 'video') ?? null,
);
const poster = computed(
    () => props.descriptor.assets.find((asset) => asset.kind === 'poster')?.url,
);

function onError(): void {
    resumeAt = player.value?.currentTime ?? 0;
    emit('asset-expired');
}

function onLoaded(): void {
    if (resumeAt > 0 && player.value !== null) {
        player.value.currentTime = resumeAt;
        resumeAt = 0;
        void player.value.play().catch(() => undefined);
    }
}
</script>

<template>
    <div
        class="relative flex h-full items-center justify-center bg-black"
        @contextmenu.prevent
    >
        <video
            v-if="video"
            ref="player"
            :key="video.url"
            :src="video.url"
            :poster="poster"
            controls
            controlslist="nodownload noplaybackrate"
            disablepictureinpicture
            preload="metadata"
            class="max-h-full max-w-full"
            @error="onError"
            @loadedmetadata="onLoaded"
        />
        <Watermark v-if="descriptor.watermark" :label="descriptor.watermark" />
    </div>
</template>
