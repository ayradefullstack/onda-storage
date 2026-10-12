<script setup lang="ts">
import { ChevronDown, ChevronUp, Loader2Icon, Search } from '@lucide/vue';
import * as pdfjs from 'pdfjs-dist';
import type { PDFDocumentLoadingTask, PDFDocumentProxy } from 'pdfjs-dist';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import type { ConsultationDescriptor } from '@/types/consultation';
import { PDF_WORKER_URL, pdfDocumentOptions } from '../original/pdfOptions';
import {
    findMatches,
    isBlockedShortcut,
    stepIndex,
} from '../original/pdfSearch';
import {
    OriginalExpiredError,
    openRangeTransport,
} from '../original/rangeSource';
import Watermark from '../Watermark.vue';

/**
 * PDF.js on canvas, from the decrypted original the server streams inline
 * over Range. Our own toolbar — the stock pdf.js viewer UI (with its download,
 * print and open-file buttons) is never used.
 *
 * - Pages render lazily (IntersectionObserver) and are released when far away.
 * - A transparent text layer gives selection and in-document search.
 * - No annotation layer is rendered at all, so a link in the PDF is plain,
 *   non-clickable text: `javascript:` URLs and external links are DISABLED.
 * - Ctrl/Cmd+P and Ctrl/Cmd+S are swallowed inside the viewer and the viewer
 *   is hidden from print — deterrents only, like the watermark.
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{ 'asset-expired': [] }>();

pdfjs.GlobalWorkerOptions.workerSrc = PDF_WORKER_URL;

const { t } = useI18n();

type Zoom = 'fit' | 1 | 1.5;

const ZOOMS: readonly Zoom[] = ['fit', 1, 1.5];

const root = ref<HTMLElement | null>(null);
const scroller = ref<HTMLElement | null>(null);
const doc = shallowRef<PDFDocumentProxy | null>(null);
let loadingTask: PDFDocumentLoadingTask | null = null;
const numPages = ref(0);
const pageSize = ref({ width: 612, height: 792 });
const zoom = ref<Zoom>('fit');
/**
 * Width pages may use in "fit" mode. Measured with `offsetWidth` (scrollbar
 * included) minus a fixed allowance, and only updated on a real width change:
 * with `clientWidth` a scrollbar appearing shrinks the width, which shrinks
 * the pages, which removes the scrollbar — an endless resize loop.
 */
const availableWidth = ref(800);
const current = ref(1);
const jump = ref('1');
const loading = ref(true);
const failed = ref(false);

const query = ref('');
const matches = ref<{ page: number; index: number }[]>([]);
const matchAt = ref(0);
const searching = ref(false);
let pageTexts: string[] | null = null;

const rendered = new Map<number, { token: number }>();
let observer: IntersectionObserver | null = null;
let renderToken = 0;
let generation = 0;

const scale = computed(() => {
    if (zoom.value !== 'fit') {
        return zoom.value;
    }

    return Math.max(0.3, availableWidth.value / pageSize.value.width);
});

const pageStyle = computed(() => ({
    width: `${Math.floor(pageSize.value.width * scale.value)}px`,
    height: `${Math.floor(pageSize.value.height * scale.value)}px`,
}));

function zoomLabel(option: Zoom): string {
    return option === 'fit' ? t('consult.zoom.fit') : `${option * 100}%`;
}

function host(n: number): HTMLElement | null {
    return (
        scroller.value?.querySelector<HTMLElement>(`[data-page="${n}"]`) ?? null
    );
}

function release(n: number): void {
    rendered.delete(n);
    host(n)?.replaceChildren();
}

async function renderPage(n: number): Promise<void> {
    const pdf = doc.value;
    const target = host(n);

    if (pdf === null || target === null || rendered.has(n)) {
        return;
    }

    const mine = { token: renderToken };
    rendered.set(n, mine);

    try {
        const page = await pdf.getPage(n);
        const viewport = page.getViewport({ scale: scale.value });
        const ratio = window.devicePixelRatio || 1;

        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width * ratio);
        canvas.height = Math.floor(viewport.height * ratio);
        canvas.style.width = `${Math.floor(viewport.width)}px`;
        canvas.style.height = `${Math.floor(viewport.height)}px`;

        await page.render({
            canvas,
            viewport,
            transform: ratio === 1 ? undefined : [ratio, 0, 0, ratio, 0, 0],
        }).promise;

        const layer = document.createElement('div');
        layer.className = 'consult-text-layer';
        layer.style.setProperty('--scale-factor', String(viewport.scale));

        const textLayer = new pdfjs.TextLayer({
            textContentSource: page.streamTextContent(),
            container: layer,
            viewport,
        });
        await textLayer.render();

        // Dropped (zoom change, released, file switched) while rendering.
        if (rendered.get(n) !== mine || mine.token !== renderToken) {
            return;
        }

        target.replaceChildren(canvas, layer);
        highlight(layer);
    } catch (error) {
        rendered.delete(n);
        handleError(error);
    }
}

function highlight(layer: HTMLElement): void {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return;
    }

    for (const span of Array.from(layer.querySelectorAll('span'))) {
        if ((span.textContent ?? '').toLowerCase().includes(needle)) {
            span.classList.add('consult-hit');
        }
    }
}

function handleError(error: unknown): void {
    const status = (error as { status?: number }).status;

    if (error instanceof OriginalExpiredError || status === 403) {
        emit('asset-expired');

        return;
    }

    if ((error as { name?: string }).name === 'RenderingCancelledException') {
        return;
    }

    failed.value = true;
}

function setupObserver(): void {
    observer?.disconnect();
    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                const n = Number((entry.target as HTMLElement).dataset.page);

                if (entry.isIntersecting) {
                    void renderPage(n);
                } else {
                    release(n);
                }
            }
        },
        { root: scroller.value, rootMargin: '1200px 0px' },
    );

    scroller.value
        ?.querySelectorAll('[data-page]')
        .forEach((element) => observer?.observe(element));
}

async function relayout(): Promise<void> {
    renderToken++;
    rendered.clear();
    scroller.value
        ?.querySelectorAll('[data-page]')
        .forEach((element) => element.replaceChildren());
    await nextTick();
    setupObserver();
}

function goTo(n: number): void {
    const page = Math.min(Math.max(1, Math.trunc(n) || 1), numPages.value);

    current.value = page;
    jump.value = String(page);
    host(page)?.scrollIntoView({ block: 'start' });
}

async function open(): Promise<void> {
    const url = props.descriptor.original?.url;

    if (!url) {
        return;
    }

    const mine = ++generation;
    const resumeAt = current.value;
    loading.value = true;
    failed.value = false;

    try {
        const range = await openRangeTransport(url, fetch, handleError);
        // The transport carries the real total length (from Content-Range).
        const task = pdfjs.getDocument({ range, ...pdfDocumentOptions() });
        const pdf = await task.promise;

        if (mine !== generation) {
            void task.destroy();

            return;
        }

        void loadingTask?.destroy();
        loadingTask = task;
        doc.value = pdf;
        numPages.value = pdf.numPages;
        pageTexts = null;

        const first = await pdf.getPage(1);
        const base = first.getViewport({ scale: 1 });
        pageSize.value = { width: base.width, height: base.height };
        loading.value = false;

        await nextTick();
        await relayout();
        goTo(resumeAt);
    } catch (error) {
        if (mine === generation) {
            loading.value = false;
            handleError(error);
        }
    }
}

function onScroll(): void {
    const top = scroller.value?.getBoundingClientRect().top ?? 0;
    let best = current.value;
    let distance = Infinity;

    for (const n of rendered.keys()) {
        const gap = Math.abs((host(n)?.getBoundingClientRect().top ?? 0) - top);

        if (gap < distance) {
            distance = gap;
            best = n;
        }
    }

    if (best !== current.value) {
        current.value = best;
        jump.value = String(best);
    }
}

function jumpToMatch(): void {
    const match = matches.value[matchAt.value];

    if (match !== undefined) {
        goTo(match.page);
    }
}

async function runSearch(): Promise<void> {
    const pdf = doc.value;

    if (pdf === null || query.value.trim() === '') {
        matches.value = [];

        return;
    }

    if (pageTexts === null) {
        searching.value = true;

        try {
            const texts: string[] = [];

            for (let n = 1; n <= pdf.numPages; n++) {
                const content = await (await pdf.getPage(n)).getTextContent();
                texts.push(
                    content.items
                        .map((item) => ('str' in item ? item.str : ''))
                        .join(' '),
                );
            }

            pageTexts = texts;
        } catch (error) {
            handleError(error);

            return;
        } finally {
            searching.value = false;
        }
    }

    matches.value = findMatches(pageTexts, query.value);
    matchAt.value = 0;
    await relayout();
    jumpToMatch();
}

function step(delta: 1 | -1): void {
    matchAt.value = stepIndex(matchAt.value, matches.value.length, delta);
    jumpToMatch();
}

function onKeydown(event: KeyboardEvent): void {
    if (isBlockedShortcut(event)) {
        event.preventDefault();
        event.stopPropagation();
    }
}

watch(zoom, () => void relayout());
watch(
    () => props.descriptor.original?.url,
    () => void open(),
);

let resizeObserver: ResizeObserver | null = null;

onMounted(() => {
    void open();
    root.value?.focus({ preventScroll: true });
    const measure = (): boolean => {
        const width = (scroller.value?.offsetWidth ?? 0) - 48;

        if (width < 100 || Math.abs(width - availableWidth.value) < 2) {
            return false;
        }

        availableWidth.value = width;

        return true;
    };

    measure();
    resizeObserver = new ResizeObserver(() => {
        if (measure() && zoom.value === 'fit' && doc.value !== null) {
            void relayout();
        }
    });

    if (scroller.value !== null) {
        resizeObserver.observe(scroller.value);
    }
});

onBeforeUnmount(() => {
    generation++;
    observer?.disconnect();
    resizeObserver?.disconnect();
    void loadingTask?.destroy();
});
</script>

<template>
    <div
        ref="root"
        class="consult-original relative flex h-full min-h-72 flex-col outline-none"
        tabindex="0"
        data-testid="pdf-viewer"
        @keydown.capture="onKeydown"
        @contextmenu.prevent
    >
        <div
            class="flex flex-wrap items-center gap-2 border-b bg-muted/40 px-2 py-1.5 text-sm"
        >
            <div class="flex items-center gap-1">
                <button
                    v-for="option in ZOOMS"
                    :key="option"
                    type="button"
                    class="rounded border px-2 py-0.5 text-xs"
                    :class="zoom === option ? 'bg-accent' : ''"
                    @click="zoom = option"
                >
                    {{ zoomLabel(option) }}
                </button>
            </div>
            <form
                class="flex items-center gap-1 text-xs"
                @submit.prevent="goTo(Number(jump))"
            >
                <label class="sr-only" for="pdf-jump">{{
                    t('consult.jumpToPage')
                }}</label>
                <input
                    id="pdf-jump"
                    v-model="jump"
                    inputmode="numeric"
                    class="w-12 rounded border bg-background px-1 py-0.5 text-center"
                />
                <span dir="ltr">/ {{ numPages }}</span>
            </form>
            <form
                class="ms-auto flex items-center gap-1"
                @submit.prevent="runSearch"
            >
                <Search class="size-4 text-muted-foreground" />
                <input
                    v-model="query"
                    type="search"
                    :placeholder="t('consult.pdf.search')"
                    :aria-label="t('consult.pdf.search')"
                    class="w-40 rounded border bg-background px-2 py-0.5 text-xs"
                />
                <span
                    class="min-w-12 text-center text-xs text-muted-foreground"
                    dir="ltr"
                    data-testid="match-count"
                >
                    <Loader2Icon
                        v-if="searching"
                        class="inline size-3 animate-spin"
                    />
                    <template v-else-if="query.trim() !== ''">
                        {{
                            matches.length === 0
                                ? t('consult.pdf.noMatch')
                                : `${matchAt + 1} / ${matches.length}`
                        }}
                    </template>
                </span>
                <button
                    type="button"
                    class="rounded border p-0.5"
                    :aria-label="t('consult.pdf.previousMatch')"
                    @click="step(-1)"
                >
                    <ChevronUp class="size-4" />
                </button>
                <button
                    type="button"
                    class="rounded border p-0.5"
                    :aria-label="t('consult.pdf.nextMatch')"
                    @click="step(1)"
                >
                    <ChevronDown class="size-4" />
                </button>
            </form>
        </div>

        <div class="relative min-h-0 flex-1">
            <div
                ref="scroller"
                class="absolute inset-0 [scrollbar-gutter:stable] overflow-auto bg-neutral-200 p-4 dark:bg-neutral-900"
                @scroll.passive="onScroll"
            >
                <div
                    v-for="n in numPages"
                    :key="n"
                    :data-page="n"
                    :style="pageStyle"
                    class="relative mx-auto mb-4 bg-white shadow select-text"
                />
                <div
                    v-if="loading"
                    class="flex h-full items-center justify-center"
                >
                    <Loader2Icon
                        class="size-8 animate-spin text-muted-foreground"
                    />
                </div>
                <p
                    v-else-if="failed"
                    class="p-6 text-center text-sm text-muted-foreground"
                >
                    {{ t('consult.reason.render_failed') }}
                </p>
            </div>
            <Watermark
                v-if="descriptor.watermark"
                :label="descriptor.watermark"
            />
        </div>
    </div>
</template>

<style scoped>
@media print {
    .consult-original {
        display: none !important;
    }
}

:deep(canvas) {
    display: block;
}

:deep(.consult-text-layer) {
    position: absolute;
    inset: 0;
    overflow: hidden;
    line-height: 1;
    text-size-adjust: none;
    forced-color-adjust: none;
    transform-origin: 0 0;
}

:deep(.consult-text-layer span),
:deep(.consult-text-layer br) {
    position: absolute;
    color: transparent;
    white-space: pre;
    cursor: text;
    transform-origin: 0% 0%;
}

:deep(.consult-text-layer ::selection) {
    background: rgb(0 100 255 / 0.3);
}

:deep(.consult-hit) {
    background: rgb(255 210 0 / 0.45);
    border-radius: 2px;
}
</style>
