<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { cn } from '@/lib/utils';

/**
 * A vertical scroll container with scroll shadows: a soft fade at the top
 * and bottom edge that is only visible when there is more content in that
 * direction. Native scrolling (keyboard, touch, wheel) is untouched; the
 * shadows are decoration and hidden from assistive technology.
 */
const props = defineProps<{
    class?: HTMLAttributes['class'];
    viewportClass?: HTMLAttributes['class'];
    /** Make the viewport focusable so it can be scrolled with the keyboard. */
    focusable?: boolean;
}>();

const emit = defineEmits<{
    /** 0..1, how far the content has been scrolled. */
    progress: [ratio: number];
}>();

const viewport = ref<HTMLElement | null>(null);
const content = ref<HTMLElement | null>(null);
const above = ref(false);
const below = ref(false);

let observer: ResizeObserver | null = null;

function update(): void {
    const el = viewport.value;

    if (el === null) {
        return;
    }

    const room = el.scrollHeight - el.clientHeight;

    above.value = el.scrollTop > 1;
    below.value = room - el.scrollTop > 1;
    emit('progress', room <= 0 ? 0 : Math.min(el.scrollTop / room, 1));
}

onMounted(() => {
    update();

    if (typeof ResizeObserver === 'undefined') {
        return;
    }

    observer = new ResizeObserver(update);

    if (viewport.value !== null) {
        observer.observe(viewport.value);
    }

    if (content.value !== null) {
        observer.observe(content.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());

defineExpose({ viewport, update });
</script>

<template>
    <div
        data-slot="scroll-area"
        :class="cn('relative flex min-h-0 flex-col', props.class)"
    >
        <div
            class="motion-scroll-shadow"
            data-edge="top"
            :data-visible="above"
            aria-hidden="true"
        />
        <div
            ref="viewport"
            data-slot="scroll-area-viewport"
            :tabindex="focusable ? 0 : undefined"
            :class="
                cn(
                    'min-h-0 flex-auto overflow-y-auto overscroll-contain outline-none focus-visible:ring-2 focus-visible:ring-ring/40 focus-visible:ring-inset',
                    viewportClass,
                )
            "
            @scroll.passive="update"
        >
            <div ref="content">
                <slot />
            </div>
        </div>
        <div
            class="motion-scroll-shadow"
            data-edge="bottom"
            :data-visible="below"
            aria-hidden="true"
        />
    </div>
</template>
