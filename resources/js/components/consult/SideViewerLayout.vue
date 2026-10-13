<script setup lang="ts">
import { ExternalLink, X } from '@lucide/vue';
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FullscreenButton from './FullscreenButton.vue';

/**
 * The two-column body of an oeuvre page: the page content (file list, cards)
 * on the start side and the side Viewer on the end side.
 *
 * From `lg` up the viewer is a sticky column whose share grows with the
 * screen (≈55 % → 62 % → 70 %) and whose height follows the viewport, so it
 * stays in view while the list scrolls. Below `lg` it is a full-height sheet
 * rising from the bottom, opened by `sheetOpen` (a tap on a file row) and
 * closed by its button, Escape or a swipe down.
 *
 * The viewer slot is rendered exactly once, in whichever of the two places
 * applies: a ConsultViewer polls and fetches, so it must not exist twice.
 */
const props = withDefaults(
    defineProps<{
        /** A file is selected: show the viewer column / allow the sheet. */
        active: boolean;
        /** Small screens only: the sheet is open. */
        sheetOpen: boolean;
        /** Name of the file shown, for the viewer header. */
        title: string;
        /** The full preview page of that file (opens in a new tab). */
        newTabHref?: string | null;
        /** Offer a close button on the desktop column too. */
        closable?: boolean;
    }>(),
    { newTabHref: null, closable: false },
);

const emit = defineEmits<{
    'update:sheetOpen': [open: boolean];
    /** The viewer was dismissed (desktop close button or the sheet). */
    close: [];
}>();

const { t } = useI18n();

// Read after mount, not during setup: the server render has no viewport, and
// a value that differs at hydration would leave the grid classes unpatched.
const wide = ref(false);
let query: MediaQueryList | null = null;

const onQueryChange = (event: MediaQueryListEvent): void => {
    wide.value = event.matches;
};

onMounted(() => {
    query = window.matchMedia('(min-width: 64rem)');
    wide.value = query.matches;
    query.addEventListener('change', onQueryChange);
});

onBeforeUnmount(() => query?.removeEventListener('change', onQueryChange));
const stage = ref<HTMLElement | null>(null);

const columns = computed(() => props.active && wide.value);
const sheet = computed(() => props.active && !wide.value && props.sheetOpen);

function dismiss(): void {
    emit('update:sheetOpen', false);
    emit('close');
}

// Swipe down on the sheet header (touch only): follow the finger with a
// transform, close past a third of the way, snap back otherwise.
const drag = ref(0);
let startY: number | null = null;

function onPointerDown(event: PointerEvent): void {
    // A tap on one of the header buttons is a tap, not the start of a drag.
    if (
        event.pointerType !== 'touch' ||
        (event.target as HTMLElement).closest('a, button') !== null
    ) {
        return;
    }

    startY = event.clientY;
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function onPointerMove(event: PointerEvent): void {
    if (startY !== null) {
        drag.value = Math.max(0, event.clientY - startY);
    }
}

function onPointerUp(): void {
    if (startY === null) {
        return;
    }

    const far = drag.value > Math.min(160, window.innerHeight / 3);

    startY = null;
    drag.value = 0;

    if (far) {
        dismiss();
    }
}
</script>

<template>
    <div
        data-testid="side-viewer-layout"
        :data-columns="columns"
        class="grid items-start gap-6"
        :class="
            columns
                ? 'lg:grid-cols-[minmax(0,45fr)_minmax(0,55fr)] xl:grid-cols-[minmax(0,38fr)_minmax(0,62fr)] 2xl:grid-cols-[minmax(0,30fr)_minmax(0,70fr)]'
                : ''
        "
    >
        <div class="@container min-w-0">
            <slot :columns="columns" />
        </div>

        <!-- lg and up: sticky column, as tall as the viewport allows. -->
        <aside
            v-if="columns"
            data-testid="side-viewer"
            class="sticky top-20 flex h-[calc(100dvh-6rem)] min-w-0 flex-col gap-2"
        >
            <div class="flex items-center gap-1">
                <h2
                    class="min-w-0 flex-1 truncate text-sm font-semibold"
                    :title="title"
                >
                    <bdi dir="ltr">{{ title }}</bdi>
                </h2>
                <FullscreenButton :target="stage" />
                <a
                    v-if="newTabHref"
                    :href="newTabHref"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40"
                    :title="t('consult.openInNewTab')"
                    :aria-label="t('consult.openInNewTab')"
                >
                    <ExternalLink class="size-4" />
                </a>
                <button
                    v-if="closable"
                    type="button"
                    class="flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40"
                    :aria-label="t('consult.closePreview')"
                    :title="t('consult.closePreview')"
                    @click="dismiss"
                >
                    <X class="size-4" />
                </button>
            </div>
            <div ref="stage" class="min-h-0 flex-1 bg-background">
                <slot name="viewer" />
            </div>
        </aside>

        <!-- Below lg: a full-height sheet from the bottom edge. -->
        <DialogRoot
            v-else
            :open="sheet"
            @update:open="(open) => (open ? null : dismiss())"
        >
            <DialogPortal>
                <DialogOverlay
                    class="app-dialog-overlay fixed inset-0 z-50 bg-black/80"
                />
                <DialogContent
                    data-testid="side-viewer"
                    class="motion-sheet fixed inset-0 z-50 flex flex-col gap-2 bg-background p-3 outline-none"
                    :style="
                        drag > 0
                            ? {
                                  transform: `translateY(${drag}px)`,
                                  transition: 'none',
                              }
                            : undefined
                    "
                >
                    <div
                        class="flex touch-none items-center gap-1"
                        @pointerdown="onPointerDown"
                        @pointermove="onPointerMove"
                        @pointerup="onPointerUp"
                        @pointercancel="onPointerUp"
                    >
                        <span
                            class="absolute inset-x-0 top-1.5 mx-auto h-1 w-10 rounded-full bg-muted-foreground/40"
                            aria-hidden="true"
                        />
                        <DialogTitle
                            class="min-w-0 flex-1 truncate pt-2 text-sm font-semibold"
                        >
                            <bdi dir="ltr">{{ title }}</bdi>
                        </DialogTitle>
                        <DialogDescription class="sr-only">
                            {{ t('consult.tab.preview') }}
                        </DialogDescription>
                        <FullscreenButton :target="stage" />
                        <a
                            v-if="newTabHref"
                            :href="newTabHref"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex size-9 shrink-0 items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40"
                            :title="t('consult.openInNewTab')"
                            :aria-label="t('consult.openInNewTab')"
                        >
                            <ExternalLink class="size-4" />
                        </a>
                        <button
                            type="button"
                            class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40"
                            :aria-label="t('consult.closePreview')"
                            @click="dismiss"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                    <div ref="stage" class="min-h-0 flex-1 bg-background">
                        <slot name="viewer" />
                    </div>
                </DialogContent>
            </DialogPortal>
        </DialogRoot>
    </div>
</template>
