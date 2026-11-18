<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { cn } from '@/lib/utils';

/**
 * The scroll container of a data table. The table scrolls INSIDE its card,
 * in both directions, so the page never scrolls sideways:
 *
 * - the header row is sticky, with a hairline and a soft shadow once rows
 *   have moved under it;
 * - on small screens the first column is sticky too, so a row keeps its
 *   identity while the other columns scroll;
 * - a scroll shadow at the bottom says there are more rows.
 *
 * It styles the plain <table> in its slot through descendant selectors, so
 * the tables themselves keep their markup.
 */
const props = defineProps<{ class?: HTMLAttributes['class'] }>();

const viewport = ref<HTMLElement | null>(null);
const above = ref(false);
const below = ref(false);
let observer: ResizeObserver | null = null;

function update(): void {
    const el = viewport.value;

    if (el === null) {
        return;
    }

    above.value = el.scrollTop > 1;
    below.value = el.scrollHeight - el.clientHeight - el.scrollTop > 1;
}

onMounted(() => {
    update();

    if (typeof ResizeObserver !== 'undefined' && viewport.value !== null) {
        observer = new ResizeObserver(update);
        observer.observe(viewport.value);

        if (viewport.value.firstElementChild !== null) {
            observer.observe(viewport.value.firstElementChild);
        }
    }
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div data-slot="table-scroll" :class="cn('relative w-full', props.class)">
        <div
            ref="viewport"
            data-slot="table-scroll-viewport"
            :data-scrolled="above"
            class="max-h-[max(24rem,calc(100dvh-11rem))] w-full overflow-auto overscroll-x-contain [&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-10 [&_thead_th]:bg-muted [&_thead_th]:shadow-[inset_0_-1px_0_var(--border)] max-md:[&_tbody_tr>*:first-child]:sticky max-md:[&_tbody_tr>*:first-child]:start-0 max-md:[&_tbody_tr>*:first-child]:z-[5] max-md:[&_tbody_tr>*:first-child]:bg-card max-md:[&_thead_th:first-child]:start-0 max-md:[&_thead_th:first-child]:z-20"
            @scroll.passive="update"
        >
            <slot />
        </div>
        <div
            class="motion-scroll-shadow"
            data-edge="bottom"
            :data-visible="below"
            aria-hidden="true"
        />
    </div>
</template>
