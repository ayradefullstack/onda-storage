<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ConsultationDescriptor } from '@/types/consultation';
import Watermark from '../Watermark.vue';

/**
 * txt, json, xml, svg ...: the SOURCE as monospace text with line numbers.
 * SVG and XML are never rendered as markup — they arrive as `text/plain`
 * and are shown through escaped interpolation only.
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{ 'asset-expired': [] }>();

const { t } = useI18n();

const text = ref<string | null>(null);
const failed = ref(false);

const asset = computed(
    () => props.descriptor.assets.find((a) => a.kind === 'text') ?? null,
);
const lines = computed(() => (text.value ?? '').split(/\r\n|\r|\n/));

async function load(): Promise<void> {
    text.value = null;
    failed.value = false;

    if (asset.value === null) {
        return;
    }

    try {
        const response = await fetch(asset.value.url, {
            credentials: 'same-origin',
        });

        if (response.status === 403) {
            emit('asset-expired');

            return;
        }

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        text.value = await response.text();
    } catch {
        failed.value = true;
    }
}

watch(() => asset.value?.url, load, { immediate: true });
</script>

<template>
    <div class="relative flex h-full min-h-0 flex-col" @contextmenu.prevent>
        <p v-if="failed" class="p-4 text-sm text-muted-foreground">
            {{ t('consult.reason.render_failed') }}
        </p>
        <p v-else-if="text === null" class="p-4 text-sm text-muted-foreground">
            {{ t('consult.preparing') }}
        </p>
        <template v-else>
            <p
                v-if="descriptor.notice === 'text_truncated'"
                class="border-b bg-muted/50 px-3 py-1.5 text-xs text-muted-foreground"
                role="status"
            >
                {{ t('consult.notice.text_truncated') }}
            </p>
            <div class="relative min-h-0 flex-1 overflow-auto">
                <Watermark
                    v-if="descriptor.watermark"
                    :label="descriptor.watermark"
                />
                <ol
                    class="min-w-max py-2 font-mono text-xs leading-5"
                    dir="ltr"
                >
                    <li
                        v-for="(line, index) in lines"
                        :key="index"
                        class="flex gap-3 px-3"
                    >
                        <span
                            class="w-10 shrink-0 text-end text-muted-foreground select-none"
                            aria-hidden="true"
                            >{{ index + 1 }}</span
                        >
                        <span class="whitespace-pre" dir="auto">{{
                            line
                        }}</span>
                    </li>
                </ol>
            </div>
        </template>
    </div>
</template>
