<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ChevronLeft, ChevronRight, Check, Copy } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tabs } from '@/components/ui/tabs';
import { silent } from '@/composables/useGlobalLoader';
import { formatBytes, formatDate, truncateFilenameMiddle } from '@/lib/format';
import type { ConsultationDescriptor } from '@/types/consultation';
import ConsultViewer from './ConsultViewer.vue';
import FullscreenButton from './FullscreenButton.vue';

/**
 * The body of a full-page file preview, shared by the admin review and the
 * author's own preview: header, previous / next with the arrow keys, the
 * viewer and, when `details` is on, a Preview / Details tab pair. The two
 * pages are thin wrappers that pass their own routes; nothing here knows
 * which area it is in. The audit trail is never a tab.
 *
 * The viewer takes everything the header leaves (100dvh). With `details`,
 * choosing the Details tab does not replace the viewer: the receipt opens
 * as a panel beside it from `xl` up and stacks under it on smaller screens.
 *
 * Two optional slots, used only by the admin page: `header-actions` (the
 * file decision, after the badges) and `before-viewer` (notices between
 * the header and the viewer).
 */
const props = defineProps<{
    oeuvre: { uuid: string; title: string | null; created_at: string };
    file: {
        uuid: string;
        original_name: string;
        extension: string;
        status: string;
    };
    siblings: {
        uuid: string;
        original_name: string;
        slot: string | null;
        review_status?: string;
    }[];
    consultation: ConsultationDescriptor;
    /** The oeuvre page this one came from. */
    backHref: string;
    /** The page of another file of the same oeuvre. */
    fileHref: (uuid: string) => string;
    /** Show the Preview / Details tabs (the author's page). */
    details?: boolean;
}>();

const { t, locale } = useI18n();

const index = computed(() =>
    props.siblings.findIndex((s) => s.uuid === props.file.uuid),
);
const previous = computed(() => props.siblings[index.value - 1] ?? null);
const next = computed(() => props.siblings[index.value + 1] ?? null);

const title = computed(
    () => props.oeuvre.title?.trim() || t('oeuvres.untitled'),
);

const tab = ref<string | number>('preview');
const tabs = computed(() => [
    { value: 'preview', label: t('consult.tab.preview') },
    { value: 'details', label: t('consult.tab.details') },
]);

function open(target: { uuid: string } | null): void {
    if (target === null) {
        return;
    }

    router.visit(props.fileHref(target.uuid));
}

function refresh(): void {
    // Background refresh (the pending-derivative poll, an expired URL):
    // never raises the global loader.
    router.reload(silent({ only: ['consultation'] }));
}

// The fullscreen target is the stage: the viewer together with its watermark.
const stage = ref<HTMLElement | null>(null);

// Reading progress, for the families that scroll one long document.
const READING: readonly string[] = ['pdf', 'document', 'text'];
const reading = computed(() => READING.includes(props.consultation.family));
const progress = ref(0);

watch(
    () => props.file.uuid,
    () => (progress.value = 0),
);

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
    <div
        class="flex min-h-[calc(100dvh-5rem)] flex-col gap-3 p-4 xl:h-[calc(100dvh-5rem)]"
        :class="details && tab === 'details' ? '' : 'h-[calc(100dvh-5rem)]'"
    >
        <header class="space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    :href="backHref"
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline"
                >
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    <bdi dir="ltr">{{ title }}</bdi>
                </Link>
                <span class="text-muted-foreground">/</span>
                <span v-if="consultation.meta.slotLabel" class="text-sm">
                    <bdi dir="ltr">{{ consultation.meta.slotLabel }}</bdi>
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <h1
                    class="min-w-0 text-lg font-semibold"
                    :title="file.original_name"
                >
                    <bdi dir="ltr">{{
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

                <slot name="header-actions" />

                <div class="ms-auto flex items-center gap-1">
                    <FullscreenButton :target="stage" />
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

            <Tabs
                v-if="details"
                v-model="tab"
                :tabs="tabs"
                variant="underline"
                size="sm"
            />

            <dl
                v-if="!details"
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

        <slot name="before-viewer" />

        <!-- Reading progress of a long document (transform only). -->
        <div
            v-if="reading && consultation.status === 'ready'"
            class="-mb-2 h-0.5 overflow-hidden rounded-full bg-muted"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="Math.round(progress * 100)"
            :aria-label="t('consult.readingProgress')"
            data-testid="reading-progress"
        >
            <div
                class="motion-progress h-full bg-primary"
                :style="{ transform: `scaleX(${progress})` }"
            />
        </div>

        <div class="flex min-h-0 flex-1 flex-col gap-4 xl:flex-row">
            <div
                ref="stage"
                class="min-h-0 flex-1 bg-background"
                :class="
                    details && tab === 'details'
                        ? 'h-[70dvh] flex-none xl:h-auto xl:flex-1'
                        : ''
                "
            >
                <ConsultViewer
                    :descriptor="consultation"
                    :file-key="file.uuid"
                    @refresh="refresh"
                    @progress="(ratio: number) => (progress = ratio)"
                />
            </div>

            <!-- Details: the deposit receipt. Beside the viewer on xl and up,
                 under it below. -->
            <dl
                v-if="details && tab === 'details'"
                data-testid="file-details"
                class="motion-fade-up grid content-start gap-x-3 gap-y-2 rounded-lg border bg-card p-4 text-sm sm:grid-cols-[10rem_1fr] xl:w-96 xl:shrink-0 xl:grid-cols-1 xl:overflow-y-auto"
            >
                <dt class="text-muted-foreground">
                    {{ t('consult.meta.name') }}
                </dt>
                <dd>
                    <bdi dir="ltr">{{ consultation.meta.filename }}</bdi>
                </dd>

                <template v-if="consultation.meta.slotLabel">
                    <dt class="text-muted-foreground">
                        {{ t('consult.meta.slot') }}
                    </dt>
                    <dd>
                        <bdi dir="ltr">{{ consultation.meta.slotLabel }}</bdi>
                    </dd>
                </template>

                <dt class="text-muted-foreground">
                    {{ t('consult.meta.format') }}
                </dt>
                <dd>
                    <bdi dir="ltr">{{ file.extension.toUpperCase() }}</bdi>
                    · {{ t(`consult.family.${consultation.family}`) }}
                </dd>

                <dt class="text-muted-foreground">
                    {{ t('consult.meta.size') }}
                </dt>
                <dd>
                    <bdi dir="ltr">{{
                        formatBytes(consultation.meta.sizeBytes, locale)
                    }}</bdi>
                </dd>

                <template v-if="consultation.meta.depositedAt">
                    <dt class="text-muted-foreground">
                        {{ t('consult.meta.deposited') }}
                    </dt>
                    <dd>
                        <bdi dir="ltr">{{
                            formatDate(consultation.meta.depositedAt, locale)
                        }}</bdi>
                    </dd>
                </template>

                <template v-if="consultation.meta.sha256">
                    <dt class="text-muted-foreground">SHA-256</dt>
                    <dd class="flex min-w-0 items-start gap-1">
                        <code dir="ltr" class="font-mono text-xs break-all">{{
                            consultation.meta.sha256
                        }}</code>
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="size-6 shrink-0"
                            :aria-label="t('consult.copyHash')"
                            @click="copyHash"
                        >
                            <Check v-if="copied" class="size-3.5" />
                            <Copy v-else class="size-3.5" />
                        </Button>
                    </dd>
                </template>

                <dt class="text-muted-foreground">
                    {{ t('consult.meta.previewStatus') }}
                </dt>
                <dd>{{ t(`consult.status.${consultation.status}`) }}</dd>
            </dl>
        </div>
    </div>
</template>
