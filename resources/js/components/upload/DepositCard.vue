<script setup lang="ts">
import {
    CheckCircle2Icon,
    FileIcon,
    PauseIcon,
    PlayIcon,
    RotateCcwIcon,
    ShieldAlertIcon,
    Trash2Icon,
    TriangleAlertIcon,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MediaPreviewDialog from '@/components/media/MediaPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
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
}>();

const emit = defineEmits<{
    pause: [id: string];
    resume: [id: string];
    cancel: [id: string];
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

// Matches MediaPreviewDialog's own `previewKind` — kept in sync by hand
// rather than shared, since this side only needs a yes/no and importing
// the dialog's internal classification for one boolean isn't worth it.
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

// ETA display: held back until the rate has settled (etaEligible), then
// smoothed on top of the store's own EMA speed so a swing doesn't visibly
// jump. State lives in this component instance, one per file (:key'd by
// entryKey in the parent), not in the store — this is a display concern.
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
        (f.status === 'paused' || f.status === 'failed') &&
        f.file !== null
    );
});

const errorMessage = computed<{ key: string; chunk: number | null } | null>(
    () => {
        const f = uploadFile.value;

        if (!f || f.status !== 'failed' || !f.errorCode) {
            return null;
        }

        return { key: `upload.error.${f.errorCode}`, chunk: f.errorChunkIndex };
    },
);

const showQuotaForFile = computed(
    () =>
        !!uploadFile.value &&
        !!props.quota &&
        (uploadFile.value.status === 'queued' ||
            uploadFile.value.status === 'initializing'),
);
const quotaForFileRemaining = computed(() => {
    if (!props.quota || !uploadFile.value) {
        return null;
    }

    return Math.max(
        0,
        props.quota.limit_bytes -
            props.quota.used_bytes -
            uploadFile.value.size,
    );
});

// --- cancel: pause is safe and instant; cancel is destructive once bytes
// have actually been sent, so it asks first and names what would be lost.
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

// --- fingerprint receipt
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
        // Clipboard API unavailable (permissions/insecure context) — the
        // hash is still shown in full via the abbreviated+title text.
    }
}
</script>

<template>
    <div
        class="rounded-lg border p-3"
        :class="{
            'border-destructive/40': alertTone === 'error',
            'border-amber-500/50': alertTone === 'quarantine',
        }"
    >
        <div class="flex items-center gap-3">
            <FileIcon class="size-5 shrink-0 text-muted-foreground" />
            <p
                class="min-w-0 flex-1 truncate text-sm font-medium"
                :title="filename"
            >
                <bdi>{{ displayName }}</bdi>
            </p>

            <div v-if="uploadFile" class="flex shrink-0 items-center gap-1">
                <Button
                    v-if="canPause"
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="t('upload.actions.pause')"
                    @click="emit('pause', uploadFile.id)"
                >
                    <PauseIcon />
                </Button>
                <Button
                    v-if="canResumeOrRetry"
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="
                        uploadFile.status === 'failed'
                            ? t('upload.actions.retry')
                            : t('upload.actions.resume')
                    "
                    @click="emit('resume', uploadFile.id)"
                >
                    <PlayIcon v-if="uploadFile.status === 'paused'" />
                    <RotateCcwIcon v-else />
                </Button>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="t('upload.actions.cancel')"
                    @click="onCancelClick"
                >
                    <Trash2Icon />
                </Button>
            </div>
        </div>

        <div
            v-if="mediaFile"
            class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground"
        >
            <bdi dir="ltr">{{ formatBytes(mediaFile.size_bytes, locale) }}</bdi>
            <bdi v-if="mediaFile.duration_sec !== null" dir="ltr">{{
                formatDuration(mediaFile.duration_sec)
            }}</bdi>
            <bdi v-if="dimensions" dir="ltr">{{ dimensions }}</bdi>
            <i18n-t
                v-if="mediaFile.variant_count > 0"
                keypath="works.show.variantCount"
            >
                <template #count
                    ><bdi dir="ltr">{{
                        mediaFile.variant_count
                    }}</bdi></template
                >
            </i18n-t>
        </div>

        <!-- Deposited: the one moment that should feel different in kind,
             not just another rail step. Collapses to just the seal once
             it was already ready when the page loaded. -->
        <template v-if="isReady">
            <DepositStateTrack
                v-if="!collapsed"
                :step-index="5"
                class="mt-2.5"
            />
            <div
                class="mt-2 flex items-start gap-2 rounded-md border border-onda-teal-600/25 bg-onda-teal-600/5 p-2.5"
            >
                <CheckCircle2Icon
                    class="mt-0.5 size-4 shrink-0 text-onda-teal-600"
                />
                <div class="min-w-0 space-y-1 text-xs">
                    <i18n-t
                        keypath="works.show.depositedOn"
                        tag="p"
                        class="font-medium text-foreground"
                    >
                        <template #date
                            ><bdi dir="ltr">{{
                                formatDate(mediaFile!.created_at, locale)
                            }}</bdi></template
                        >
                    </i18n-t>
                    <div
                        v-if="abbreviatedHash"
                        class="flex flex-wrap items-center gap-x-2 gap-y-1"
                    >
                        <span class="text-muted-foreground">{{
                            t('works.show.fingerprint')
                        }}</span>
                        <bdi dir="ltr" class="font-mono">{{
                            abbreviatedHash
                        }}</bdi>
                        <button
                            type="button"
                            class="text-onda-blue-600 hover:underline dark:text-onda-blue-400"
                            @click="copyFingerprint"
                        >
                            {{
                                fingerprintCopied
                                    ? t('works.show.fingerprintCopied')
                                    : t('works.show.copyFingerprint')
                            }}
                        </button>
                    </div>
                    <p class="text-muted-foreground">
                        {{ t('works.show.auditNotice') }}
                    </p>
                    <Button
                        v-if="isPreviewable"
                        size="sm"
                        variant="outline"
                        class="h-7 text-xs"
                        @click="previewOpen = true"
                    >
                        {{ t('works.show.view') }}
                    </Button>
                </div>
            </div>
        </template>

        <!-- Failed / quarantined / expired / quota exceeded: an alert to
             read and act on, never a colored rail segment. -->
        <template v-else-if="alertTone">
            <div
                class="mt-2 flex items-start gap-2 rounded-md border p-2.5 text-xs"
                :class="
                    alertTone === 'quarantine'
                        ? 'border-amber-500/40 bg-amber-500/5'
                        : 'border-destructive/30 bg-destructive/5'
                "
            >
                <component
                    :is="
                        alertTone === 'quarantine'
                            ? ShieldAlertIcon
                            : TriangleAlertIcon
                    "
                    class="mt-0.5 size-4 shrink-0"
                    :class="
                        alertTone === 'quarantine'
                            ? 'text-amber-600'
                            : 'text-destructive'
                    "
                />
                <div class="min-w-0 space-y-1.5" role="alert">
                    <i18n-t
                        v-if="mediaFile?.status === 'failed'"
                        keypath="media.failedWithRef"
                        tag="p"
                    >
                        <template #ref
                            ><bdi dir="ltr" class="font-mono">{{
                                reference
                            }}</bdi></template
                        >
                    </i18n-t>

                    <i18n-t
                        v-else-if="mediaFile?.status === 'quarantined'"
                        keypath="media.quarantineWithRef"
                        tag="p"
                    >
                        <template #ref
                            ><bdi dir="ltr" class="font-mono">{{
                                reference
                            }}</bdi></template
                        >
                        <template #hotline
                            ><bdi dir="ltr">{{
                                t('sidebar.hotline.number')
                            }}</bdi></template
                        >
                    </i18n-t>

                    <template
                        v-else-if="uploadFile?.status === 'quota_exceeded'"
                    >
                        <i18n-t
                            v-if="uploadFile.remainingQuotaBytes !== null"
                            keypath="upload.error.quotaExceededWithRemaining"
                            tag="p"
                        >
                            <template #remaining
                                ><bdi dir="ltr">{{
                                    formatBytes(
                                        uploadFile.remainingQuotaBytes,
                                        locale,
                                    )
                                }}</bdi></template
                            >
                            <template #hotline
                                ><bdi dir="ltr">{{
                                    t('sidebar.hotline.number')
                                }}</bdi></template
                            >
                        </i18n-t>
                        <i18n-t
                            v-else
                            keypath="upload.error.quotaExceeded"
                            tag="p"
                        >
                            <template #hotline
                                ><bdi dir="ltr">{{
                                    t('sidebar.hotline.number')
                                }}</bdi></template
                            >
                        </i18n-t>
                    </template>

                    <p
                        v-else-if="
                            uploadFile?.status === 'expired' &&
                            uploadFile.errorCode === 'authExpired'
                        "
                    >
                        {{ t('upload.error.authExpired') }}
                    </p>
                    <p v-else-if="uploadFile?.status === 'expired'">
                        {{ t('upload.error.expired') }}
                    </p>

                    <template v-else-if="errorMessage">
                        <i18n-t
                            v-if="errorMessage.chunk !== null"
                            :keypath="`${errorMessage.key}WithChunk`"
                            tag="p"
                        >
                            <template #index
                                ><bdi dir="ltr">{{
                                    errorMessage.chunk
                                }}</bdi></template
                            >
                        </i18n-t>
                        <p v-else>{{ t(errorMessage.key) }}</p>
                    </template>

                    <Button
                        v-if="canResumeOrRetry"
                        size="sm"
                        variant="outline"
                        class="h-7 text-xs"
                        @click="emit('resume', uploadFile!.id)"
                    >
                        {{ t('upload.actions.retry') }}
                    </Button>
                </div>
            </div>
        </template>

        <!-- In progress: the custody rail plus one honest line for the
             current stage. Never a fake percentage inside a processing
             stage the backend hasn't reported progress for. -->
        <template v-else>
            <DepositStateTrack
                :step-index="stepIndex"
                :tone="railTone"
                class="mt-2.5"
            />

            <div class="mt-1.5 text-xs text-muted-foreground">
                <template
                    v-if="
                        uploadFile?.status === 'uploading' ||
                        uploadFile?.status === 'paused'
                    "
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                        <bdi dir="ltr"
                            >{{
                                formatBytes(uploadFile.bytesUploaded, locale)
                            }}
                            / {{ formatBytes(uploadFile.size, locale) }}</bdi
                        >
                        <bdi
                            v-if="uploadFile.status === 'uploading'"
                            dir="ltr"
                            >{{ formatSpeed(uploadFile.speedBps, locale) }}</bdi
                        >
                        <i18n-t
                            v-if="
                                uploadFile.status === 'uploading' &&
                                displayedEtaSeconds !== null
                            "
                            keypath="upload.eta"
                            tag="span"
                        >
                            <template #time
                                ><bdi dir="ltr">{{
                                    formatDuration(displayedEtaSeconds)
                                }}</bdi></template
                            >
                        </i18n-t>
                    </div>
                    <p class="mt-1">
                        {{
                            uploadFile.status === 'uploading'
                                ? t('upload.persistence.line')
                                : t('upload.status.paused')
                        }}
                    </p>
                </template>

                <template
                    v-else-if="
                        uploadFile?.status === 'queued' ||
                        uploadFile?.status === 'initializing'
                    "
                >
                    <p>{{ t('works.show.stage.queued') }}</p>
                    <i18n-t
                        v-if="
                            showQuotaForFile && quotaForFileRemaining !== null
                        "
                        keypath="works.show.quotaForFile"
                        tag="p"
                        class="mt-0.5"
                    >
                        <template #size
                            ><bdi dir="ltr">{{
                                formatBytes(uploadFile.size, locale)
                            }}</bdi></template
                        >
                        <template #remaining
                            ><bdi dir="ltr">{{
                                formatBytes(quotaForFileRemaining, locale)
                            }}</bdi></template
                        >
                    </i18n-t>
                </template>

                <p v-else-if="uploadFile?.status === 'completing'">
                    {{ t('works.show.stage.finishing') }}
                </p>
                <p v-else-if="mediaFile?.status === 'assembling'">
                    {{ t('works.show.stage.finishing') }}
                </p>
                <p v-else-if="mediaFile?.status === 'scanning'">
                    {{ t('works.show.stage.checking') }}
                </p>
                <p v-else-if="mediaFile?.status === 'processing'">
                    {{ t('works.show.stage.preparing') }}
                </p>
            </div>
        </template>

        <Dialog v-model:open="cancelConfirmOpen">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>{{
                        t('upload.cancel.confirmTitle')
                    }}</DialogTitle>
                </DialogHeader>
                <i18n-t
                    v-if="uploadFile"
                    keypath="upload.cancel.confirmBody"
                    tag="p"
                    class="text-sm text-muted-foreground"
                >
                    <template #sent
                        ><bdi dir="ltr">{{
                            formatBytes(uploadFile.bytesUploaded, locale)
                        }}</bdi></template
                    >
                </i18n-t>
                <DialogFooter>
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

        <MediaPreviewDialog
            v-if="mediaFile"
            :open="previewOpen"
            :media-file="mediaFile"
            @update:open="previewOpen = $event"
        />
    </div>
</template>
