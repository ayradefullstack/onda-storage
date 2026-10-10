<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import type { ConsultationDescriptor, SheetData } from '@/types/consultation';
import Watermark from '../Watermark.vue';

/**
 * spreadsheet / csv: the derivative is JSON of cell VALUES only. Cells are
 * rendered through Vue's escaped interpolation — never as HTML — so a cell
 * holding markup is shown as text. Horizontal scroll stays inside the table
 * area; the header row is sticky.
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
}>();

const emit = defineEmits<{ 'asset-expired': [] }>();

const { t } = useI18n();

const PAGE = 500;

const data = ref<SheetData | null>(null);
const failed = ref(false);
const active = ref(0);
const visibleRows = ref(PAGE);

const asset = computed(
    () => props.descriptor.assets.find((a) => a.kind === 'sheet') ?? null,
);

const sheet = computed(() => data.value?.sheets[active.value] ?? null);
const header = computed(() => sheet.value?.rows[0] ?? []);
const body = computed(() =>
    (sheet.value?.rows.slice(1) ?? []).slice(0, visibleRows.value),
);
const hiddenRows = computed(() =>
    Math.max(0, (sheet.value?.rows.length ?? 1) - 1 - visibleRows.value),
);

async function load(): Promise<void> {
    data.value = null;
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

        data.value = (await response.json()) as SheetData;
        active.value = 0;
        visibleRows.value = PAGE;
    } catch {
        failed.value = true;
    }
}

watch(() => asset.value?.url, load, { immediate: true });
watch(active, () => {
    visibleRows.value = PAGE;
});
</script>

<template>
    <div class="flex h-full min-h-0 flex-col" @contextmenu.prevent>
        <p v-if="failed" class="p-4 text-sm text-muted-foreground">
            {{ t('consult.reason.render_failed') }}
        </p>
        <p v-else-if="data === null" class="p-4 text-sm text-muted-foreground">
            {{ t('consult.preparing') }}
        </p>
        <template v-else>
            <div
                v-if="data.sheets.length > 1"
                class="flex gap-1 overflow-x-auto border-b px-2 py-1.5"
                role="tablist"
            >
                <Button
                    v-for="(s, index) in data.sheets"
                    :key="index"
                    type="button"
                    size="sm"
                    role="tab"
                    :aria-selected="active === index"
                    :variant="active === index ? 'secondary' : 'ghost'"
                    @click="active = index"
                >
                    <bdi>{{ s.name }}</bdi>
                </Button>
            </div>
            <p
                v-if="sheet?.truncated"
                class="border-b bg-muted/50 px-3 py-1.5 text-xs text-muted-foreground"
                role="status"
            >
                {{ t('consult.notice.sheet_truncated') }}
            </p>
            <div class="relative min-h-0 flex-1 overflow-auto">
                <Watermark
                    v-if="descriptor.watermark"
                    :label="descriptor.watermark"
                />
                <table class="min-w-full border-collapse text-sm">
                    <thead
                        v-if="header.length"
                        class="sticky top-0 z-20 bg-muted"
                    >
                        <tr>
                            <th
                                v-for="(cell, c) in header"
                                :key="c"
                                class="border px-2 py-1 text-start font-medium"
                            >
                                <bdi>{{ cell }}</bdi>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, r) in body" :key="r">
                            <td
                                v-for="(cell, c) in row"
                                :key="c"
                                class="border px-2 py-1 align-top"
                            >
                                <bdi>{{ cell }}</bdi>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="hiddenRows > 0" class="p-3 text-center">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="visibleRows += PAGE"
                    >
                        {{ t('consult.showMoreRows', { n: hiddenRows }) }}
                    </Button>
                </div>
            </div>
        </template>
    </div>
</template>
