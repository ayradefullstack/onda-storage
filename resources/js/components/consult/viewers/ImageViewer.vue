<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import type { ConsultationDescriptor } from '@/types/consultation';
import Watermark from '../Watermark.vue';

/** One resized WebP with zoom (wheel / buttons) and pan (drag). */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{ 'asset-expired': [] }>();

const { t } = useI18n();

const scale = ref(1);
const x = ref(0);
const y = ref(0);
let dragging = false;
let lastX = 0;
let lastY = 0;

const image = computed(
    () =>
        props.descriptor.assets.find((asset) => asset.kind === 'image') ?? null,
);

function zoomBy(delta: number): void {
    scale.value = Math.min(8, Math.max(0.25, scale.value + delta));
}

function reset(): void {
    scale.value = 1;
    x.value = 0;
    y.value = 0;
}

function down(event: PointerEvent): void {
    dragging = true;
    lastX = event.clientX;
    lastY = event.clientY;
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function move(event: PointerEvent): void {
    if (!dragging) {
        return;
    }

    x.value += event.clientX - lastX;
    y.value += event.clientY - lastY;
    lastX = event.clientX;
    lastY = event.clientY;
}

function up(): void {
    dragging = false;
}

function wheel(event: WheelEvent): void {
    zoomBy(event.deltaY < 0 ? 0.15 : -0.15);
}
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div class="flex items-center gap-1 border-b px-3 py-2 text-sm">
            <Button
                type="button"
                size="sm"
                variant="ghost"
                @click="zoomBy(-0.25)"
            >
                −
            </Button>
            <span dir="ltr" class="w-12 text-center tabular-nums">
                {{ Math.round(scale * 100) }}%
            </span>
            <Button
                type="button"
                size="sm"
                variant="ghost"
                @click="zoomBy(0.25)"
            >
                +
            </Button>
            <Button type="button" size="sm" variant="ghost" @click="reset">
                {{ t('consult.zoom.reset') }}
            </Button>
            <span
                v-if="descriptor.notice === 'first_frame_only'"
                class="ms-auto text-xs text-muted-foreground"
            >
                {{ t('consult.notice.first_frame_only') }}
            </span>
        </div>
        <div
            class="relative min-h-0 flex-1 touch-none overflow-hidden bg-muted/30"
            data-testid="image-stage"
            @contextmenu.prevent
            @pointerdown="down"
            @pointermove="move"
            @pointerup="up"
            @pointercancel="up"
            @wheel.prevent="wheel"
        >
            <Watermark
                v-if="descriptor.watermark"
                :label="descriptor.watermark"
            />
            <img
                v-if="image"
                :src="image.url"
                :alt="descriptor.meta.filename"
                draggable="false"
                class="mx-auto h-full max-w-full cursor-grab object-contain select-none"
                :style="{
                    transform: `translate(${x}px, ${y}px) scale(${scale})`,
                }"
                @error="emit('asset-expired')"
            />
        </div>
    </div>
</template>
