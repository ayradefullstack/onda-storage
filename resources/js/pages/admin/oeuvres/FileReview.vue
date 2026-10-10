<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronLeft, ChevronRight, Check, Copy } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConsultViewer from '@/components/consult/ConsultViewer.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatBytes, formatDate, truncateFilenameMiddle } from '@/lib/format';
import { dashboard } from '@/routes/admin';
import {
    index as oeuvresIndex,
    show as oeuvreShow,
} from '@/routes/admin/oeuvres';
import { review } from '@/routes/admin/oeuvres/files';
import type { ConsultationDescriptor } from '@/types/consultation';

const props = defineProps<{
    oeuvre: { uuid: string; title: string | null; created_at: string };
    file: {
        uuid: string;
        original_name: string;
        extension: string;
        status: string;
    };
    siblings: { uuid: string; original_name: string; slot: string | null }[];
    consultation: ConsultationDescriptor;
}>();

const { t, locale } = useI18n();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: dashboard() },
            { title: 'Works', href: oeuvresIndex() },
        ],
    },
});

const index = computed(() =>
    props.siblings.findIndex((s) => s.uuid === props.file.uuid),
);
const previous = computed(() => props.siblings[index.value - 1] ?? null);
const next = computed(() => props.siblings[index.value + 1] ?? null);

const title = computed(
    () => props.oeuvre.title?.trim() || t('oeuvres.untitled'),
);

function open(target: { uuid: string } | null): void {
    if (target === null) {
        return;
    }

    router.visit(
        review({ oeuvre: props.oeuvre.uuid, mediaFile: target.uuid }).url,
    );
}

function refresh(): void {
    router.reload({ only: ['consultation'] });
}

function onKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;

    if (
        target !== null &&
        (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) ||
            target.isContentEditable)
    ) {
        return;
    }

    if (event.key === 'ArrowLeft') {
        open(document.dir === 'rtl' ? next.value : previous.value);
    } else if (event.key === 'ArrowRight') {
        open(document.dir === 'rtl' ? previous.value : next.value);
    }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const copied = ref(false);

async function copyHash(): Promise<void> {
    if (props.consultation.meta.sha256 === null) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.consultation.meta.sha256);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        // Clipboard can be blocked; the hash stays selectable on screen.
    }
}
</script>

<template>
    <Head :title="file.original_name" />

    <div class="flex h-[calc(100dvh-5rem)] flex-col gap-3 p-4">
        <header class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    :href="oeuvreShow(oeuvre.uuid)"
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline"
                >
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    <bdi>{{ title }}</bdi>
                </Link>
                <span class="text-muted-foreground">/</span>
                <span v-if="consultation.meta.slotLabel" class="text-sm">
                    <bdi>{{ consultation.meta.slotLabel }}</bdi>
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <h1
                    class="min-w-0 text-lg font-semibold"
                    :title="file.original_name"
                >
                    <bdi>{{
                        truncateFilenameMiddle(file.original_name, 60)
                    }}</bdi>
                </h1>
                <Badge variant="outline">
                    {{ t(`consult.family.${consultation.family}`) }}
                </Badge>
                <Badge
                    :variant="
                        consultation.status === 'ready'
                            ? 'success'
                            : consultation.status === 'failed'
                              ? 'destructive'
                              : 'outline'
                    "
                >
                    {{ t(`consult.status.${consultation.status}`) }}
                </Badge>

                <div class="ms-auto flex items-center gap-1">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="previous === null"
                        :aria-label="t('consult.previousFile')"
                        @click="open(previous)"
                    >
                        <ChevronLeft class="size-4 rtl:rotate-180" />
                    </Button>
                    <span
                        dir="ltr"
                        class="px-1 text-sm text-muted-foreground tabular-nums"
                    >
                        {{ index + 1 }} / {{ siblings.length }}
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="next === null"
                        :aria-label="t('consult.nextFile')"
                        @click="open(next)"
                    >
                        <ChevronRight class="size-4 rtl:rotate-180" />
                    </Button>
                </div>
            </div>

            <dl
                class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted-foreground"
            >
                <div class="flex gap-1">
                    <dt>{{ t('consult.meta.size') }}</dt>
                    <dd>
                        <bdi dir="ltr">{{
                            formatBytes(consultation.meta.sizeBytes, locale)
                        }}</bdi>
                    </dd>
                </div>
                <div v-if="consultation.meta.depositedAt" class="flex gap-1">
                    <dt>{{ t('consult.meta.deposited') }}</dt>
                    <dd>
                        <bdi dir="ltr">{{
                            formatDate(consultation.meta.depositedAt, locale)
                        }}</bdi>
                    </dd>
                </div>
                <div
                    v-if="consultation.meta.sha256"
                    class="flex min-w-0 items-center gap-1"
                >
                    <dt>SHA-256</dt>
                    <dd class="min-w-0">
                        <code
                            dir="ltr"
                            class="font-mono text-[11px] break-all"
                            >{{ consultation.meta.sha256 }}</code
                        >
                    </dd>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        class="size-6"
                        :aria-label="t('consult.copyHash')"
                        @click="copyHash"
                    >
                        <Check v-if="copied" class="size-3.5" />
                        <Copy v-else class="size-3.5" />
                    </Button>
                </div>
            </dl>
        </header>

        <div class="min-h-0 flex-1">
            <ConsultViewer
                :descriptor="consultation"
                :file-key="file.uuid"
                @refresh="refresh"
            />
        </div>
    </div>
</template>
