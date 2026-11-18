<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ConsultationDescriptor } from '@/types/consultation';
import MediaUnreadableCard from '../original/MediaUnreadableCard.vue';
import { useOriginalMedia } from '../original/useOriginalMedia';
import Watermark from '../Watermark.vue';

/**
 * A plain <audio>: the signed consult-original URL for an `original_inline`
 * file, else the 128 kbps derivative with the waveform the pipeline already
 * made. Same expiry / resume / unreadable handling as the video viewer.
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{ 'asset-expired': [] }>();

const player = ref<HTMLAudioElement | null>(null);
const seconds = ref<number | null>(null);

const src = computed(
    () =>
        props.descriptor.original?.url ??
        props.descriptor.assets.find((asset) => asset.kind === 'audio')?.url ??
        null,
);
const waveform = computed(
    () =>
        props.descriptor.assets.find((asset) => asset.kind === 'waveform') ??
        null,
);

const { unreadable, onError, onLoadedMetadata } = useOriginalMedia(
    player,
    () => src.value ?? '',
    () => emit('asset-expired'),
);

function onLoaded(): void {
    seconds.value = player.value?.duration ?? null;
    onLoadedMetadata();
}
</script>

<template>
    <MediaUnreadableCard
        v-if="unreadable"
        kind="audio"
        :mime="descriptor.original?.mime ?? descriptor.meta.mime"
    />
    <div
        v-else
        class="relative flex h-full flex-col items-center justify-center gap-4 p-4"
        @contextmenu.prevent
    >
        <img
            v-if="waveform"
            :src="waveform.url"
            alt=""
            draggable="false"
            class="w-full max-w-2xl rounded-md bg-neutral-900 select-none"
            @error="emit('asset-expired')"
        />
        <audio
            v-if="src"
            ref="player"
            :key="src"
            :src="src"
            controls
            controlslist="nodownload noplaybackrate"
            preload="metadata"
            class="w-full max-w-2xl"
            @error="onError"
            @loadedmetadata="onLoaded"
        />
        <p
            v-if="seconds !== null && Number.isFinite(seconds) && !compact"
            class="font-mono text-xs text-muted-foreground"
            dir="ltr"
        >
            {{ Math.round(seconds) }}s
        </p>
        <Watermark v-if="descriptor.watermark" :label="descriptor.watermark" />
    </div>
</template>
