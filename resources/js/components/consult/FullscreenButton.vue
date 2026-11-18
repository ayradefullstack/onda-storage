<script setup lang="ts">
import { Maximize, Minimize } from '@lucide/vue';
import { useFullscreen } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * "Plein écran" for a viewer: puts the given element (the viewer WITH its
 * watermark overlay) in the browser's fullscreen; Esc leaves, as always.
 * Hidden where the Fullscreen API is not available (iPhone Safari).
 */
const props = defineProps<{ target: HTMLElement | null }>();

const { t } = useI18n();

const target = computed(() => props.target);
const { isSupported, isFullscreen, toggle } = useFullscreen(target);

// Shown only after mount: the server cannot know whether the browser has the
// Fullscreen API, and the two renders must agree.
const mounted = ref(false);

onMounted(() => (mounted.value = true));
</script>

<template>
    <button
        v-if="mounted && isSupported"
        type="button"
        data-testid="viewer-fullscreen"
        class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40"
        :aria-pressed="isFullscreen"
        :aria-label="
            isFullscreen ? t('consult.exitFullscreen') : t('consult.fullscreen')
        "
        :title="
            isFullscreen ? t('consult.exitFullscreen') : t('consult.fullscreen')
        "
        @click="toggle"
    >
        <Minimize v-if="isFullscreen" class="size-4" />
        <Maximize v-else class="size-4" />
    </button>
</template>
