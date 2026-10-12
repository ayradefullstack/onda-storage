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

const emit = defineEmits<{ 'asset-expired': [] }>();

const { t } = useI18n();
const frame = ref<HTMLIFrameElement | null>(null);
const state = ref<'loading' | 'ready' | 'failed'>('loading');
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
        state.value = 'ready';
    } catch {
        if (mine === generation) {
            state.value = 'failed';
        }
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
    generation++;
});
</script>

<template>
    <div class="relative h-full min-h-72" @contextmenu.prevent>
        <iframe
            ref="frame"
            :sandbox="DOCX_IFRAME_SANDBOX"
            :srcdoc="DOCX_IFRAME_SRCDOC"
            :title="descriptor.meta.filename"
            referrerpolicy="no-referrer"
            class="size-full border-0 bg-white"
            data-testid="docx-frame"
            @load="onFrameLoad"
        />
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
