<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import type { ConsultationDescriptor } from '@/types/consultation';
import Watermark from '../Watermark.vue';

/**
 * pdf / document / presentation: the server already turned every page into a
 * WebP; this is a vertical scroller with zoom and page navigation. No iframe,
 * <embed> or <object>: the browser's native PDF viewer has its own download
 * button, and these are derivatives anyway.
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{ 'asset-expired': [] }>();

const { t } = useI18n();

type Zoom = 'fit' | '100' | '150';

const zoom = ref<Zoom>('fit');
const current = ref(1);
const jump = ref('1');
const container = ref<HTMLElement | null>(null);

const pages = computed(() =>
    props.descriptor.assets
        .filter((asset) => asset.kind === 'page')
        .sort((a, b) => (a.pageIndex ?? 0) - (b.pageIndex ?? 0)),
);

const pageWidth = computed(() => {
    if (zoom.value === '100') {
        return '900px';
    }

    if (zoom.value === '150') {
        return '1350px';
    }

    return '100%';
});

let observer: IntersectionObserver | null = null;

function observe(): void {
    observer?.disconnect();

    if (
        container.value === null ||
        typeof IntersectionObserver === 'undefined'
    ) {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    const index = Number(
                        (entry.target as HTMLElement).dataset.page,
                    );
                    current.value = index;
                    jump.value = String(index);
                }
            }
        },
        { root: container.value, threshold: 0.5 },
    );

    container.value
        .querySelectorAll('[data-page]')
        .forEach((node) => observer?.observe(node));
}

function goTo(page: number): void {
    const clamped = Math.min(Math.max(1, page), pages.value.length);
    container.value
        ?.querySelector(`[data-page="${clamped}"]`)
        ?.scrollIntoView({ block: 'start' });
    current.value = clamped;
    jump.value = String(clamped);
}

function submitJump(): void {
    const parsed = Number.parseInt(jump.value, 10);

    if (!Number.isNaN(parsed)) {
        goTo(parsed);
    }
}

onMounted(observe);
watch(pages, () => setTimeout(observe, 0));
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <div
            class="flex flex-wrap items-center gap-2 border-b px-3 py-2 text-sm"
        >
            <div class="flex items-center gap-1" role="group">
                <Button
                    v-for="option in ['fit', '100', '150'] as Zoom[]"
                    :key="option"
                    type="button"
                    size="sm"
                    :variant="zoom === option ? 'secondary' : 'ghost'"
                    :aria-pressed="zoom === option"
                    @click="zoom = option"
                >
                    {{
                        option === 'fit' ? t('consult.zoom.fit') : `${option}%`
                    }}
                </Button>
            </div>
            <form
                class="ms-auto flex items-center gap-1"
                @submit.prevent="submitJump"
            >
                <label class="sr-only" for="consult-jump">
                    {{ t('consult.jumpToPage') }}
                </label>
                <input
                    id="consult-jump"
                    v-model="jump"
                    inputmode="numeric"
                    dir="ltr"
                    class="h-8 w-14 rounded-md border bg-background px-2 text-center text-sm"
                />
                <span dir="ltr" class="text-muted-foreground">
                    / {{ pages.length }}
                </span>
            </form>
        </div>

        <p
            v-if="descriptor.notice === 'pages_truncated'"
            class="border-b bg-muted/50 px-3 py-1.5 text-xs text-muted-foreground"
            role="status"
        >
            {{ t('consult.notice.pages_truncated', { n: pages.length }) }}
        </p>

        <div
            ref="container"
            class="relative min-h-0 flex-1 overflow-auto bg-muted/30 p-3"
            data-testid="page-scroller"
            @contextmenu.prevent
        >
            <Watermark
                v-if="descriptor.watermark"
                :label="descriptor.watermark"
            />
            <div
                v-for="page in pages"
                :key="page.pageIndex ?? 0"
                :data-page="(page.pageIndex ?? 0) + 1"
                class="mx-auto mb-3 min-h-40 bg-white shadow-sm"
                :style="{
                    width: pageWidth,
                    maxWidth: zoom === 'fit' ? '100%' : 'none',
                }"
            >
                <img
                    :src="page.url"
                    :alt="
                        t('consult.pageAlt', { n: (page.pageIndex ?? 0) + 1 })
                    "
                    loading="lazy"
                    draggable="false"
                    class="block w-full select-none"
                    @error="emit('asset-expired')"
                />
            </div>
        </div>
    </div>
</template>
