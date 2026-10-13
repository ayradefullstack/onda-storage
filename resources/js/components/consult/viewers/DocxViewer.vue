<script setup lang="ts">
import { Loader2Icon } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ConsultationDescriptor } from '@/types/consultation';
import {
    DOCX_IFRAME_SANDBOX,
    DOCX_IFRAME_SRCDOC,
    makeLinksInert,
} from '../original/docxFrame';
import Watermark from '../Watermark.vue';

/**
 * DOCX through `docx-preview`, rendered client-side from the decrypted
 * original the server streams inline. The file is fetched as a blob and
 * written into a sandboxed iframe (no `allow-scripts`, CSP `default-src
 * 'none'`) by THIS component — never injected as markup into the app's own
 * DOM, and no `v-html` anywhere.
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{
    'asset-expired': [];
    /** 0..1, how far the document has been scrolled. */
    'scroll-progress': [ratio: number];
}>();

function reportProgress(element: HTMLElement): void {
    const room = element.scrollHeight - element.clientHeight;

    emit(
        'scroll-progress',
        room <= 0 ? 0 : Math.min(element.scrollTop / room, 1),
    );
}

const { t } = useI18n();
const frame = ref<HTMLIFrameElement | null>(null);
const state = ref<'loading' | 'ready' | 'failed'>('loading');
const contentHeight = ref<number | null>(null);
let contentObserver: ResizeObserver | null = null;
let frameReady = false;
let generation = 0;

async function load(): Promise<void> {
    const url = props.descriptor.original?.url;
    const doc = frame.value?.contentDocument;

    if (!url || !frameReady || !doc) {
        return;
    }

    const mine = ++generation;
    state.value = 'loading';

    try {
        const response = await fetch(url, { credentials: 'same-origin' });

        if (response.status === 403 || response.status === 401) {
            emit('asset-expired');

            return;
        }

        if (!response.ok) {
            throw new Error(`status ${response.status}`);
        }

        const blob = await response.blob();
        const { renderAsync } = await import('docx-preview');

        if (mine !== generation) {
            return;
        }

        doc.body.replaceChildren();
        await renderAsync(blob, doc.body, doc.head, {
            inWrapper: true,
            ignoreWidth: false,
            breakPages: true,
            useBase64URL: true,
            renderHeaders: true,
            renderFooters: true,
        });
        makeLinksInert(doc);
        followContentHeight(doc);
        state.value = 'ready';
    } catch {
        if (mine === generation) {
            state.value = 'failed';
        }
    }
}

/**
 * The iframe is as tall as the document inside it, so there is ONE vertical
 * scroll (the viewer's own) instead of a frame scrolling inside a box. The
 * height is re-read whenever the rendered document changes size (fonts and
 * images settle after the first paint; a narrower viewer wraps differently).
 */
function followContentHeight(doc: Document): void {
    const read = (): void => {
        // The body, not the root element: the root is never shorter than
        // the frame itself, so reading it could only ever grow the frame.
        contentHeight.value = Math.ceil(doc.body.scrollHeight);
    };

    contentObserver?.disconnect();
    read();

    if (typeof ResizeObserver !== 'undefined') {
        contentObserver = new ResizeObserver(read);
        contentObserver.observe(doc.body);
    }
}

function onFrameLoad(): void {
    frameReady = true;
    void load();
}

onMounted(() => {
    if (frame.value?.contentDocument?.readyState === 'complete') {
        // srcdoc may already have loaded before the listener attached.
        onFrameLoad();
    }
});

watch(
    () => props.descriptor.original?.url,
    () => void load(),
);

onBeforeUnmount(() => {
    contentObserver?.disconnect();
    generation++;
});
</script>

<template>
    <div class="relative h-full min-h-72" @contextmenu.prevent>
        <div
            class="absolute inset-0 overflow-x-hidden overflow-y-auto"
            data-testid="docx-scroller"
            @scroll.passive="
                reportProgress($event.currentTarget as HTMLElement)
            "
        >
            <iframe
                ref="frame"
                :sandbox="DOCX_IFRAME_SANDBOX"
                :srcdoc="DOCX_IFRAME_SRCDOC"
                :title="descriptor.meta.filename"
                referrerpolicy="no-referrer"
                class="block w-full border-0 bg-white"
                :class="contentHeight === null ? 'h-full' : ''"
                :style="
                    contentHeight === null
                        ? undefined
                        : { height: `${contentHeight}px` }
                "
                data-testid="docx-frame"
                @load="onFrameLoad"
            />
        </div>
        <div
            v-if="state === 'loading'"
            class="absolute inset-0 flex items-center justify-center bg-background/70"
        >
            <Loader2Icon class="size-8 animate-spin text-muted-foreground" />
        </div>
        <p
            v-else-if="state === 'failed'"
            class="absolute inset-0 flex items-center justify-center bg-background p-6 text-center text-sm text-muted-foreground"
        >
            {{ t('consult.reason.render_failed') }}
        </p>
        <Watermark v-if="descriptor.watermark" :label="descriptor.watermark" />
    </div>
</template>
