<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ConsultationDescriptor } from '@/types/consultation';

/** The 128 kbps derivative, with the waveform image the pipeline already made. */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{ 'asset-expired': [] }>();

const player = ref<HTMLAudioElement | null>(null);
let resumeAt = 0;

const audio = computed(
    () =>
        props.descriptor.assets.find((asset) => asset.kind === 'audio') ?? null,
);
const waveform = computed(
    () =>
        props.descriptor.assets.find((asset) => asset.kind === 'waveform') ??
        null,
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
        class="flex h-full flex-col items-center justify-center gap-4 p-4"
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
            v-if="audio"
            ref="player"
            :key="audio.url"
            :src="audio.url"
            controls
            controlslist="nodownload noplaybackrate"
            preload="metadata"
            class="w-full max-w-2xl"
            @error="onError"
            @loadedmetadata="onLoaded"
        />
    </div>
</template>
