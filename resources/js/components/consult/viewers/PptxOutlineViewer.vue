<script setup lang="ts">
import { Loader2Icon } from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ConsultationDescriptor, PptxOutline } from '@/types/consultation';
import Watermark from '../Watermark.vue';

/**
 * The text outline of a pptx: a numbered list of slides with their text and
 * speaker notes. NOT a visual rendering — a notice says so. Everything is
 * shown through Vue's escaped interpolation; there is no `v-html`.
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{ 'asset-expired': [] }>();

const { t } = useI18n();
const outline = ref<PptxOutline | null>(null);
const failed = ref(false);

async function load(): Promise<void> {
    const url = props.descriptor.assets.find(
        (asset) => asset.kind === 'outline',
    )?.url;

    if (!url) {
        failed.value = true;

        return;
    }

    try {
        const response = await fetch(url, { credentials: 'same-origin' });

        if (response.status === 403) {
            emit('asset-expired');

            return;
        }

        if (!response.ok) {
            throw new Error(`status ${response.status}`);
        }

        outline.value = (await response.json()) as PptxOutline;
        failed.value = false;
    } catch {
        failed.value = true;
    }
}

onMounted(() => void load());
watch(
    () => props.descriptor.assets.map((asset) => asset.url).join('|'),
    () => void load(),
);
</script>

<template>
    <div
        class="relative flex h-full min-h-72 flex-col"
        data-testid="pptx-outline"
        @contextmenu.prevent
    >
        <p class="border-b bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            {{ t('consult.pptx.notice') }}
        </p>
        <div class="relative min-h-0 flex-1 overflow-auto p-4">
            <div
                v-if="outline === null && !failed"
                class="flex h-full items-center justify-center"
            >
                <Loader2Icon
                    class="size-8 animate-spin text-muted-foreground"
                />
            </div>
            <p
                v-else-if="failed"
                class="text-center text-sm text-muted-foreground"
            >
                {{ t('consult.reason.render_failed') }}
            </p>
            <ol v-else-if="outline" class="space-y-4">
                <li
                    v-for="slide in outline.slides"
                    :key="slide.index"
                    class="rounded-md border p-3"
                >
                    <h3 class="mb-2 text-sm font-semibold">
                        {{ t('consult.pptx.slide', { n: slide.index }) }}
                    </h3>
                    <p
                        v-if="slide.texts.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        {{ t('consult.pptx.noText') }}
                    </p>
                    <ul v-else class="list-disc space-y-1 ps-5 text-sm">
                        <li v-for="(text, i) in slide.texts" :key="i">
                            {{ text }}
                        </li>
                    </ul>
                    <div
                        v-if="slide.notes.length > 0"
                        class="mt-3 border-t pt-2 text-xs text-muted-foreground"
                    >
                        <p class="mb-1 font-medium">
                            {{ t('consult.pptx.notes') }}
                        </p>
                        <p v-for="(note, i) in slide.notes" :key="i">
                            {{ note }}
                        </p>
                    </div>
                </li>
            </ol>
            <Watermark
                v-if="descriptor.watermark"
                :label="descriptor.watermark"
            />
        </div>
    </div>
</template>
