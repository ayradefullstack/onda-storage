<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ConsultationDescriptor } from '@/types/consultation';
import MediaUnreadableCard from '../original/MediaUnreadableCard.vue';
import { useOriginalMedia } from '../original/useOriginalMedia';
import Watermark from '../Watermark.vue';

/**
 * A plain <video>. For an `original_inline` file the source is the signed
 * consult-original URL (decrypted on the fly, Range); for a `derivative` one
 * it is the 480p asset. No native download or picture-in-picture, no context
 * menu. Dimensions and duration come from the element itself. When the signed
 * URL expires mid-play the position is remembered, fresh descriptors are
 * requested through `asset-expired`, and playback resumes at the same second;
 * a codec the browser cannot decode shows a card, never a download.
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{ 'asset-expired': [] }>();

const player = ref<HTMLVideoElement | null>(null);
const facts = ref<{ width: number; height: number; seconds: number } | null>(
    null,
);

const src = computed(
    () =>
        props.descriptor.original?.url ??
        props.descriptor.assets.find((asset) => asset.kind === 'video')?.url ??
        null,
);
const poster = computed(
    () => props.descriptor.assets.find((asset) => asset.kind === 'poster')?.url,
);

const { unreadable, onError, onLoadedMetadata } = useOriginalMedia(
    player,
    () => src.value ?? '',
    () => emit('asset-expired'),
);

function onLoaded(): void {
    const element = player.value;

    if (element !== null) {
        facts.value = {
            width: element.videoWidth,
            height: element.videoHeight,
            seconds: element.duration,
        };
    }

    onLoadedMetadata();
}
</script>

<template>
    <MediaUnreadableCard
        v-if="unreadable"
        kind="video"
        :mime="descriptor.original?.mime ?? descriptor.meta.mime"
    />
    <div
        v-else
        class="relative flex h-full flex-col items-center justify-center bg-black"
        @contextmenu.prevent
    >
        <div
            class="relative flex min-h-0 w-full flex-1 items-center justify-center"
        >
            <video
                v-if="src"
                ref="player"
                :key="src"
                :src="src"
                :poster="poster"
                controls
                controlslist="nodownload noplaybackrate"
                disablepictureinpicture
                preload="metadata"
                class="size-full object-contain"
                @error="onError"
                @loadedmetadata="onLoaded"
            />
            <Watermark
                v-if="descriptor.watermark"
                :label="descriptor.watermark"
            />
        </div>
        <p
            v-if="facts && facts.width > 0 && !compact"
            class="py-1 font-mono text-xs text-neutral-400"
            dir="ltr"
            data-testid="media-facts"
        >
            {{ facts.width }}×{{ facts.height }} ·
            {{ Math.round(facts.seconds) }}s
        </p>
    </div>
</template>
