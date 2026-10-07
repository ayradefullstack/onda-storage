<script setup lang="ts">
import {
    Activity,
    ArrowUpRight,
    Check,
    CheckCircle2,
    CodeXml,
    Copy,
    Eye,
    File,
    FileText,
    Film,
    Image,
    Music,
    Pause,
    Play,
    RotateCcw,
    ShieldAlert,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MediaPreviewDialog from '@/components/media/MediaPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    alertToneOf,
    createEtaSmoother,
    etaEligible,
    filenameOf,
    railStepIndex,
    railToneOf,
    referenceFor,
} from '@/components/upload/depositJourney';
import type { DepositEntry } from '@/components/upload/depositJourney';
import DepositStateTrack from '@/components/upload/DepositStateTrack.vue';
import {
    formatBytes,
    formatDate,
    formatDuration,
    formatSpeed,
    truncateFilenameMiddle,
} from '@/lib/format';

const props = defineProps<{
    entry: DepositEntry;
    quota?: { used_bytes: number; limit_bytes: number } | null;
    editable?: boolean;
}>();

const emit = defineEmits<{
    pause: [id: string];
    resume: [id: string];
    cancel: [id: string];
    remove: [uuid: string];
}>();

const { t, locale } = useI18n();

const uploadFile = computed(() =>
    props.entry.kind === 'upload' ? props.entry.file : null,
);
const mediaFile = computed(() =>
    props.entry.kind === 'media' ? props.entry.file : null,
);

const filename = computed(() => filenameOf(props.entry));
const displayName = computed(() => truncateFilenameMiddle(filename.value));

const alertTone = computed(() => alertToneOf(props.entry));
const stepIndex = computed(() => railStepIndex(props.entry));
const railTone = computed(() => railToneOf(props.entry));
const reference = computed(() => referenceFor(props.entry));

const collapsed = computed(
    () => props.entry.kind === 'media' && props.entry.collapsedReady,
);
const isReady = computed(() => mediaFile.value?.status === 'ready');

// Upload percentage calculation
const uploadPercent = computed(() => {
    const f = uploadFile.value;

    if (!f || f.size === 0) {
        return 0;
    }

    return Math.min(
        100,
        Math.max(0, Math.round((f.bytesUploaded / f.size) * 100)),
    );
});

// File icon and tone based on extension / MIME
const fileDetails = computed(() => {
    const ext = (
        uploadFile.value?.extension ||
        mediaFile.value?.extension ||
        ''
    ).toLowerCase();
    const mime = (
        uploadFile.value?.mime ||
        mediaFile.value?.mime ||
        ''
    ).toLowerCase();

    if (
        mime.startsWith('audio/') ||
        ['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a', 'aiff'].includes(ext)
    ) {
        return {
            icon: Music,
            color: 'text-violet-600 dark:text-violet-400',
            bg: 'bg-violet-500/10',
        };
    }

    if (
        mime.startsWith('video/') ||
        ['mp4', 'mkv', 'mov', 'avi', 'webm'].includes(ext)
    ) {
        return {
            icon: Film,
            color: 'text-sky-600 dark:text-sky-400',
            bg: 'bg-sky-500/10',
        };
    }

    if (
        mime === 'application/pdf' ||
        ['pdf', 'doc', 'docx', 'txt', 'rtf'].includes(ext)
    ) {
        return {
            icon: FileText,
            color: 'text-amber-600 dark:text-amber-400',
            bg: 'bg-amber-500/10',
        };
    }

    if (
        mime.startsWith('image/') ||
        ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'].includes(ext)
    ) {
        return {
            icon: Image,
            color: 'text-emerald-600 dark:text-emerald-400',
            bg: 'bg-emerald-500/10',
        };
    }

    if (['zip', 'tar', 'gz', 'json', 'xml', 'py', 'js', 'ts'].includes(ext)) {
        return {
            icon: CodeXml,
            color: 'text-indigo-600 dark:text-indigo-400',
            bg: 'bg-indigo-500/10',
        };
    }

    return { icon: File, color: 'text-muted-foreground', bg: 'bg-muted/40' };
});

const isPreviewable = computed(() => {
    const mime = mediaFile.value?.mime ?? '';

    return (
        mime.startsWith('image/') ||
        mime.startsWith('video/') ||
        mime.startsWith('audio/') ||
        mime === 'application/pdf'
    );
});
const previewOpen = ref(false);

const dimensions = computed(() => {
    const m = mediaFile.value;

    return m?.width && m?.height ? `${m.width}×${m.height}` : null;
});

// ETA display: smoothed on top of EMA speed
const smoothEta = createEtaSmoother();
const displayedEtaSeconds = ref<number | null>(null);

watch(
    () => {
        const f = uploadFile.value;

        return f && etaEligible(f) ? f.etaSeconds : null;
    },
    (raw) => {
        displayedEtaSeconds.value = smoothEta(raw);
    },
    { immediate: true },
);

const canPause = computed(
    () =>
        uploadFile.value?.status === 'uploading' ||
        uploadFile.value?.status === 'initializing',
);
const canResumeOrRetry = computed(() => {
    const f = uploadFile.value;

    return (
        !!f &&
        (f.status === 'paused' ||
            f.status === 'failed' ||
            f.status === 'expired') &&
        f.file !== null
    );
});

interface ErrorText {
    key: string;
    params: { message?: string; status?: string | number };
}

const errorMessage = computed<ErrorText | null>(() => {
    const f = uploadFile.value;

    if (!f || f.status !== 'failed' || !f.errorCode) {
        return null;
    }

    // The server's own validation text (slot full, format not allowed).
    if (f.errorCode === 'validation') {
        return f.errorMessage
            ? {
                  key: 'upload.error.validation',
                  params: { message: f.errorMessage },
              }
            : { key: 'upload.error.validationFallback', params: {} };
    }

    return {
        key: `upload.error.${f.errorCode}`,
        params: { status: f.errorStatus ?? '' },
    };
});

// 413: how much room is left, and how much THIS file needs.
const quotaMessage = computed(() => {
    const f = uploadFile.value;

    if (!f || f.status !== 'quota_exceeded') {
        return null;
    }

    const hotline = t('sidebar.hotline.number');

    return f.remainingQuotaBytes === null
        ? t('upload.error.quotaExceeded', { hotline })
        : t('upload.error.quotaExceededDetail', {
              needed: formatBytes(f.neededQuotaBytes ?? f.size, locale.value),
              remaining: formatBytes(f.remainingQuotaBytes, locale.value),
              hotline,
          });
});

const removeConfirmOpen = ref(false);
const onRemoveConfirm = () => {
    if (mediaFile.value !== null) {
        emit('remove', mediaFile.value.uuid);
    }

    removeConfirmOpen.value = false;
};

const cancelConfirmOpen = ref(false);
function onCancelClick(): void {
    const f = uploadFile.value;

    if (!f) {
        return;
    }

    if (f.bytesUploaded > 0) {
        cancelConfirmOpen.value = true;
    } else {
        emit('cancel', f.id);
    }
}

function confirmCancel(): void {
    if (uploadFile.value) {
        emit('cancel', uploadFile.value.id);
    }

    cancelConfirmOpen.value = false;
}

const fingerprintCopied = ref(false);
const abbreviatedHash = computed(() => {
    const hash = mediaFile.value?.sha256_plain;

    return hash ? `${hash.slice(0, 12)}…${hash.slice(-8)}` : null;
});

async function copyFingerprint(): Promise<void> {
    const hash = mediaFile.value?.sha256_plain;

    if (!hash) {
        return;
    }

    try {
        await navigator.clipboard.writeText(hash);
        fingerprintCopied.value = true;
        setTimeout(() => {
            fingerprintCopied.value = false;
        }, 2000);
    } catch {
        // Fallback
    }
}
</script>

<template>
    <div
        class="group/card relative overflow-hidden rounded-2xl border p-4 transition-all duration-300"
        :class="[
            alertTone === 'error'
                ? 'border-destructive/40 bg-destructive/5'
                : alertTone === 'quarantine'
                  ? 'border-amber-500/50 bg-amber-500/5'
                  : isReady
                    ? 'border-emerald-500/30 bg-card/80 shadow-xs'
                    : uploadFile?.status === 'uploading'
                      ? 'border-onda-blue-500/50 bg-card shadow-sm ring-1 ring-onda-blue-500/20'
                      : 'border-border/80 bg-card/60',
        ]"
    >
        <!-- File Header Row -->
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 flex-1 items-center gap-3">
                <!-- File Icon -->
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl transition-transform duration-200 group-hover/card:scale-105"
                    :class="fileDetails.bg"
                >
                    <component
                        :is="fileDetails.icon"
                        class="size-5 shrink-0"
                        :class="fileDetails.color"
                    />
                </div>

                <!-- Name & Meta -->
                <div class="min-w-0 flex-1">
                    <p
                        class="truncate text-sm font-semibold tracking-tight text-foreground"
                        :title="filename"
                    >
                        <bdi>{{ displayName }}</bdi>
                    </p>

                    <div
                        v-if="mediaFile"
                        class="mt-0.5 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-xs text-muted-foreground"
                    >
                        <span class="font-mono font-medium text-foreground/80">
                            <bdi dir="ltr">{{
                                formatBytes(mediaFile.size_bytes, locale)
                            }}</bdi>
                        </span>
                        <span
                            v-if="mediaFile.duration_sec !== null"
                            class="font-mono"
                        >
                            •
                            <bdi dir="ltr">{{
                                formatDuration(mediaFile.duration_sec)
                            }}</bdi>
                        </span>
                        <span v-if="dimensions" class="font-mono">
                            • <bdi dir="ltr">{{ dimensions }}</bdi>
                        </span>
                        <span
                            v-if="mediaFile.variant_count > 0"
                            class="text-emerald-600 dark:text-emerald-400"
                        >
                            •
                            {{
                                t('oeuvres.show.variantCount', {
                                    count: mediaFile.variant_count,
                                })
                            }}
                        </span>
                    </div>

                    <!-- Uploading Mini Meta -->
                    <div
                        v-else-if="uploadFile"
                        class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
                    >
                        <span class="font-mono font-medium text-foreground/80">
                            <bdi dir="ltr">{{
                                formatBytes(uploadFile.size, locale)
                            }}</bdi>
                        </span>
                        <span>•</span>
                        <span
                            v-if="uploadFile.status === 'uploading'"
                            class="inline-flex items-center gap-1 font-medium text-onda-blue-600 dark:text-onda-blue-400"
                        >
                            <Activity class="size-3 animate-pulse" />
                            <bdi dir="ltr">{{
                                formatSpeed(uploadFile.speedBps, locale)
                            }}</bdi>
                        </span>
                        <span
                            v-else-if="uploadFile.status === 'paused'"
                            class="font-medium text-amber-600 dark:text-amber-400"
                        >
                            {{ t('upload.status.paused') }}
                        </span>
                        <span v-else class="text-muted-foreground">
                            {{ t(`upload.status.${uploadFile.status}`) }}
                        </span>
                        <!-- The client is retrying by itself (429 / 502 / 503 / 504). -->
                        <span
                            v-if="uploadFile.notice"
                            role="status"
                            class="font-medium text-amber-600 dark:text-amber-400"
                        >
                            • {{ t(`upload.notice.${uploadFile.notice}`) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Action Controls -->
            <div class="flex shrink-0 items-center gap-1.5">
                <template v-if="uploadFile">
                    <Button
                        v-if="canPause"
                        variant="ghost"
                        size="icon-sm"
                        class="size-8 cursor-pointer rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground"
                        :aria-label="t('upload.actions.pause')"
                        @click="emit('pause', uploadFile.id)"
                    >
                        <Pause class="size-4" />
                    </Button>
                    <Button
                        v-if="canResumeOrRetry"
                        variant="ghost"
                        size="icon-sm"
                        class="size-8 cursor-pointer rounded-lg text-onda-blue-600 hover:bg-onda-blue-500/10 hover:text-onda-blue-700 dark:text-onda-blue-400"
                        :aria-label="
                            uploadFile.status === 'failed'
                                ? t('upload.actions.retry')
                                : t('upload.actions.resume')
                        "
                        @click="emit('resume', uploadFile.id)"
                    >
                        <Play
                            v-if="uploadFile.status === 'paused'"
                            class="size-4"
                        />
                        <RotateCcw v-else class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="size-8 cursor-pointer rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        :aria-label="t('upload.actions.cancel')"
                        @click="onCancelClick"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </template>

                <template v-else-if="mediaFile && editable">
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="size-8 cursor-pointer rounded-lg text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        :disabled="!mediaFile.can_remove"
                        :aria-label="t('oeuvres.files.remove')"
                        :title="
                            mediaFile.can_remove
                                ? t('oeuvres.files.remove')
                                : t('oeuvres.files.removeBusy')
                        "
                        @click="removeConfirmOpen = true"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </template>
            </div>
        </div>

        <!-- ANIMATED PROGRESS SECTION (Uploading & Chunk transfer) -->
        <div
            v-if="
                uploadFile &&
                (uploadFile.status === 'uploading' ||
                    uploadFile.status === 'paused' ||
                    uploadFile.status === 'completing')
            "
            class="mt-3.5 space-y-2 rounded-xl bg-muted/40 p-3"
        >
            <div class="flex items-center justify-between text-xs">
                <div class="flex items-center gap-2">
                    <span class="font-mono font-bold text-foreground">
                        {{ uploadPercent }}%
                    </span>
                    <span
                        v-if="uploadFile.status === 'uploading'"
                        class="inline-flex items-center gap-1 text-[11px] text-onda-blue-600 dark:text-onda-blue-400"
                    >
                        <ArrowUpRight class="size-3" />
                        {{ t('upload.persistence.line') }}
                    </span>
                </div>

                <div
                    class="flex items-center gap-2 font-mono text-[11px] text-muted-foreground"
                >
                    <bdi dir="ltr">
                        {{ formatBytes(uploadFile.bytesUploaded, locale) }} /
                        {{ formatBytes(uploadFile.size, locale) }}
                    </bdi>
                    <span
                        v-if="
                            displayedEtaSeconds !== null &&
                            uploadFile.status === 'uploading'
                        "
                    >
                        • {{ formatDuration(displayedEtaSeconds) }}
                    </span>
                </div>
            </div>

            <!-- Dynamic Animated Bar with Shimmer Effect -->
            <div
                class="relative h-2 w-full overflow-hidden rounded-full bg-muted shadow-inner"
            >
                <div
                    class="h-full rounded-full transition-all duration-300 ease-out"
                    :class="[
                        uploadFile.status === 'paused'
                            ? 'bg-amber-500'
                            : 'bg-gradient-to-r from-onda-blue-600 via-onda-blue-500 to-onda-teal-500 shadow-onda-glow-blue',
                    ]"
                    :style="{ width: `${uploadPercent}%` }"
                >
                    <!-- Animated Shimmer Stripe -->
                    <div
                        v-if="uploadFile.status === 'uploading'"
                        class="absolute inset-0 animate-pulse bg-gradient-to-r from-transparent via-white/30 to-transparent"
                    />
                </div>
            </div>

            <!-- Custody Rail Mini Tracking -->
            <DepositStateTrack
                :step-index="stepIndex"
                :tone="railTone"
                class="pt-1"
            />
        </div>

        <!-- SERVER PROCESSING STAGES (Assembling, Scanning, Processing) -->
        <div
            v-else-if="
                mediaFile &&
                ['assembling', 'scanning', 'processing'].includes(
                    mediaFile.status,
                )
            "
            class="mt-3.5 space-y-2.5 rounded-xl border border-onda-blue-500/20 bg-onda-blue-500/5 p-3"
        >
            <div class="flex items-center justify-between text-xs">
                <div
                    class="flex items-center gap-2 font-medium text-onda-blue-700 dark:text-onda-blue-300"
                >
                    <span class="relative flex size-2">
                        <span
                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-onda-blue-500 opacity-75"
                        />
                        <span
                            class="relative inline-flex size-2 rounded-full bg-onda-blue-600"
                        />
                    </span>
                    <span>
                        {{
                            mediaFile.status === 'assembling'
                                ? t('oeuvres.show.stage.finishing')
                                : mediaFile.status === 'scanning'
                                  ? t('oeuvres.show.stage.checking')
                                  : t('oeuvres.show.stage.preparing')
                        }}
                    </span>
                </div>

                <span class="font-mono text-[11px] text-muted-foreground">
                    {{ t('oeuvres.show.securing') }}
                </span>
            </div>

            <!-- Indeterminate Animated Bar -->
            <div
                class="relative h-1.5 w-full overflow-hidden rounded-full bg-muted/60"
            >
                <div
                    class="h-full w-1/3 animate-[indeterminate_1.5s_infinite_linear] rounded-full bg-gradient-to-r from-onda-blue-600 to-onda-teal-500"
                />
            </div>

            <!-- Custody Rail -->
            <DepositStateTrack :step-index="stepIndex" :tone="railTone" />
        </div>

        <!-- DEPOSITED & READY SEALED STATE -->
        <template v-else-if="isReady">
            <DepositStateTrack
                v-if="!collapsed"
                :step-index="5"
                class="mt-3 border-t border-border/60 pt-1"
            />

            <div
                class="mt-3 flex flex-col gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/5 p-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex min-w-0 items-start gap-2.5">
                    <CheckCircle2
                        class="mt-0.5 size-4.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                    />
                    <div class="min-w-0 space-y-1 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-foreground">
                                {{
                                    t('oeuvres.show.depositedOn', {
                                        date: formatDate(
                                            mediaFile!.created_at,
                                            locale,
                                        ),
                                    })
                                }}
                            </span>
                            <span
                                class="py-0.2 rounded bg-emerald-500/15 px-1.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300"
                            >
                                {{ t('oeuvres.show.certified') }}
                            </span>
                        </div>

                        <div
                            v-if="abbreviatedHash"
                            class="flex flex-wrap items-center gap-x-2 text-[11px]"
                        >
                            <span class="text-muted-foreground"
                                >{{ t('oeuvres.show.fingerprint') }} :</span
                            >
                            <span
                                class="rounded border border-border/80 bg-background/80 px-1.5 py-0.5 font-mono font-medium text-foreground"
                            >
                                <bdi dir="ltr">{{ abbreviatedHash }}</bdi>
                            </span>
                            <button
                                type="button"
                                class="inline-flex cursor-pointer items-center gap-1 font-medium text-onda-blue-600 hover:underline dark:text-onda-blue-400"
                                @click="copyFingerprint"
                            >
                                <component
                                    :is="fingerprintCopied ? Check : Copy"
                                    class="size-3"
                                />
                                <span>{{
                                    fingerprintCopied
                                        ? t('oeuvres.show.fingerprintCopied')
                                        : t('oeuvres.show.copyFingerprint')
                                }}</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    class="flex shrink-0 items-center gap-2 self-end sm:self-center"
                >
                    <Button
                        v-if="isPreviewable"
                        size="sm"
                        variant="outline"
                        class="h-8 cursor-pointer gap-1.5 border-border text-xs font-semibold shadow-xs hover:border-onda-blue-500/50"
                        @click="previewOpen = true"
                    >
                        <Eye class="size-3.5" />
                        <span>{{ t('oeuvres.show.view') }}</span>
                    </Button>
                </div>
            </div>
        </template>

        <!-- ALERTS: Failed / Quarantined / Quota exceeded -->
        <template v-else-if="alertTone">
            <div
                class="mt-3 flex items-start gap-2.5 rounded-xl border p-3 text-xs"
                :class="
                    alertTone === 'quarantine'
                        ? 'border-amber-500/40 bg-amber-500/10'
                        : 'border-destructive/30 bg-destructive/10'
                "
            >
                <component
                    :is="
                        alertTone === 'quarantine' ? ShieldAlert : TriangleAlert
                    "
                    class="mt-0.5 size-4 shrink-0"
                    :class="
                        alertTone === 'quarantine'
                            ? 'text-amber-600 dark:text-amber-400'
                            : 'text-destructive'
                    "
                />
                <div class="min-w-0 flex-1 space-y-1.5" role="alert">
                    <p
                        v-if="mediaFile?.status === 'failed'"
                        class="font-semibold text-foreground"
                    >
                        {{ t('media.failedWithRef', { ref: reference }) }}
                    </p>
                    <p
                        v-else-if="mediaFile?.status === 'quarantined'"
                        class="font-semibold text-foreground"
                    >
                        {{
                            t('media.quarantineWithRef', {
                                ref: reference,
                                hotline: t('sidebar.hotline.number'),
                            })
                        }}
                    </p>
                    <p
                        v-else-if="uploadFile?.status === 'quota_exceeded'"
                        class="font-semibold text-foreground"
                    >
                        {{ quotaMessage }}
                    </p>
                    <p v-else-if="errorMessage" class="text-foreground">
                        {{ t(errorMessage.key, errorMessage.params) }}
                    </p>

                    <Button
                        v-if="canResumeOrRetry"
                        size="sm"
                        variant="outline"
                        class="h-7 cursor-pointer text-xs font-semibold"
                        @click="emit('resume', uploadFile!.id)"
                    >
                        <RotateCcw class="me-1 size-3" />
                        {{ t('upload.actions.retry') }}
                    </Button>
                </div>
            </div>
        </template>

        <!-- REMOVE DIALOG -->
        <Dialog v-model:open="removeConfirmOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        t('oeuvres.files.removeTitle')
                    }}</DialogTitle>
                    <DialogDescription>
                        <i18n-t keypath="oeuvres.files.removeBody" tag="span">
                            <template #name>
                                <bdi class="font-semibold text-foreground">{{
                                    filename
                                }}</bdi>
                            </template>
                        </i18n-t>
                    </DialogDescription>
                </DialogHeader>

                <p
                    class="rounded-xl border border-amber-500/25 bg-amber-500/5 p-3 text-xs leading-relaxed text-muted-foreground"
                >
                    {{ t('oeuvres.files.removeBytes') }}
                </p>

                <DialogFooter class="gap-2 sm:gap-2">
                    <Button
                        variant="outline"
                        class="cursor-pointer"
                        @click="removeConfirmOpen = false"
                    >
                        {{ t('oeuvres.files.removeCancel') }}
                    </Button>
                    <Button
                        variant="destructive"
                        class="cursor-pointer"
                        @click="onRemoveConfirm"
                    >
                        {{ t('oeuvres.files.removeConfirm') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- CANCEL DIALOG -->
        <Dialog v-model:open="cancelConfirmOpen">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>{{
                        t('upload.cancel.confirmTitle')
                    }}</DialogTitle>
                </DialogHeader>
                <p v-if="uploadFile" class="text-sm text-muted-foreground">
                    {{
                        t('upload.cancel.confirmBody', {
                            sent: formatBytes(uploadFile.bytesUploaded, locale),
                        })
                    }}
                </p>
                <DialogFooter class="gap-2 sm:gap-2">
                    <Button
                        variant="outline"
                        @click="cancelConfirmOpen = false"
                    >
                        {{ t('upload.cancel.keepGoing') }}
                    </Button>
                    <Button variant="destructive" @click="confirmCancel">
                        {{ t('upload.cancel.confirmAction') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- PREVIEW MODAL -->
        <MediaPreviewDialog
            v-if="mediaFile"
            :open="previewOpen"
            :media-file="mediaFile"
            @update:open="previewOpen = $event"
        />
    </div>
</template>

<style scoped>
@keyframes indeterminate {
    0% {
        transform: translateX(-100%);
    }
    100% {
        transform: translateX(400%);
    }
}
</style>
