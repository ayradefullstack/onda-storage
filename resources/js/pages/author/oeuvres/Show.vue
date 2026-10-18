<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    HardDrive,
    Lock,
    Send,
    ShieldCheck,
    Sparkles,
} from '@lucide/vue';
import { computed, nextTick, onMounted, provide, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import ConsultViewer from '@/components/consult/ConsultViewer.vue';
import { FILE_PREVIEW } from '@/components/consult/previewContext';
import SideViewerLayout from '@/components/consult/SideViewerLayout.vue';
import { useSideViewer } from '@/components/consult/useSideViewer';
import ClassificationCard from '@/components/oeuvre/ClassificationCard.vue';
import type { OeuvreClassification } from '@/components/oeuvre/ClassificationCard.vue';
import { oeuvreLabel } from '@/components/oeuvre/label';
import OeuvreSubmitDialog from '@/components/oeuvre/OeuvreSubmitDialog.vue';
import SubmitArea from '@/components/oeuvre/SubmitArea.vue';
import DepositStatusBanner from '@/components/review/DepositStatusBanner.vue';
import MobileFixBar from '@/components/review/MobileFixBar.vue';
import ReviewSummary from '@/components/review/ReviewSummary.vue';
import ReviewTimeline from '@/components/review/ReviewTimeline.vue';
import type { ReviewEntry } from '@/components/review/ReviewTimeline.vue';
import type { HistoryEvent } from '@/components/review/SlotHistory.vue';
import ToFixPanel from '@/components/review/ToFixPanel.vue';
import type { ToFixItem } from '@/components/review/ToFixPanel.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import DepositCard from '@/components/upload/DepositCard.vue';
import type { DepositEntry } from '@/components/upload/depositJourney';
import {
    entryKey,
    groupEntriesByRequirement,
    mergeDepositEntries,
} from '@/components/upload/depositJourney';
import Dropzone from '@/components/upload/Dropzone.vue';
import FileStatusPollNotice from '@/components/upload/FileStatusPollNotice.vue';
import type { ReplacementState } from '@/components/upload/replacement';
import {
    createRemovalFlow,
    EMPTY_REPLACEMENT,
    fileAnchor,
    focusReplacementDropzone,
    openFor,
    replacementDropzoneId,
    revealTarget,
} from '@/components/upload/replacement';
import type { RequirementSlot } from '@/components/upload/requirement';
import RequirementSlotCard from '@/components/upload/RequirementSlotCard.vue';
import ResumeBanner from '@/components/upload/ResumeBanner.vue';
import { useFileStatusPoll } from '@/composables/useFileStatusPoll';
import { silent } from '@/composables/useGlobalLoader';
import { prefersReducedMotion } from '@/composables/useReducedMotion';
import { useUploadQueue } from '@/composables/useUploadQueue';
import { formatBytes, formatDate } from '@/lib/format';
import {
    allowedExtensionList,
    MAX_FILE_SIZE_BYTES,
} from '@/lib/uploadValidation';
import { index } from '@/routes/oeuvres';
import {
    destroy as destroyFile,
    preview,
    status as fileStatus,
} from '@/routes/oeuvres/files';
import { assets as previewAssets } from '@/routes/oeuvres/files/preview';
import type { ConsultationDescriptor } from '@/types/consultation';
import type { MediaFileSummary } from '@/types/upload';
import { FINAL_UPLOAD_ERRORS } from '@/types/upload';

interface OeuvreDetail {
    id: number;
    uuid: string;
    title: string | null;
    college_name: string | null;
    code_college_snapshot: string | null;
    description: string | null;
    status: string;
    created_at: string;
    submitted_at: string | null;
    registered_at: string | null;
    can: { edit: boolean; delete: boolean };
}

interface SubmissionReason {
    code: string;
    params: Record<string, string | number | null>;
    message: string;
}

interface Submission {
    can_submit: boolean;
    blockers: SubmissionReason[];
    advisories: SubmissionReason[];
    is_open: boolean;
}

interface Quota {
    used_bytes: number;
    limit_bytes: number;
}

interface RequiredDocumentsProgress {
    satisfied: number;
    total: number;
    conditional: number;
}

const props = defineProps<{
    oeuvre: OeuvreDetail;
    classification: OeuvreClassification | null;
    mediaFiles: MediaFileSummary[];
    quota: Quota;
    requirements: RequirementSlot[];
    progress: RequiredDocumentsProgress;
    submission: Submission;
    /** The decision history. Carries no officer identity — see the controller. */
    reviews: ReviewEntry[];
    /** Under review: which slots take a replacement (server-computed). */
    replacement?: ReplacementState;
    /** Under review: the counters and each slot's state; null otherwise. */
    review_summary?: {
        accepted: number;
        waiting: number;
        to_fix: number;
        uploading: number;
        last_upload_at: string | null;
        slots: Record<string, 'to_fix' | 'waiting' | 'accepted'> | [];
    } | null;
    /** Per slot ("none" for no slot): the document's history. */
    history?: Record<string, HistoryEvent[]> | [];
}>();

const { t, locale } = useI18n();

defineOptions({
    layout: {
        // An i18n key: Breadcrumbs translates it in the reader's locale.
        breadcrumbs: [{ title: 'sidebar.nav.oeuvres', href: index() }],
    },
});

const editable = computed(() => props.oeuvre.can.edit);

// The side Viewer: the same ConsultViewer as the full preview page. The
// selection state (and the stale-reply guard) lives in useSideViewer; this
// only supplies how an owner's descriptor is fetched.
async function loadDescriptor(
    uuid: string,
): Promise<ConsultationDescriptor | null> {
    try {
        const response = await fetch(
            previewAssets({ oeuvre: props.oeuvre.uuid, mediaFile: uuid }).url,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );

        if (!response.ok) {
            return null;
        }

        return (
            (await response.json()) as { consultation: ConsultationDescriptor }
        ).consultation;
    } catch {
        return null;
    }
}

const {
    selectedUuid,
    descriptor: viewerDescriptor,
    select: selectFile,
    refresh: refreshViewer,
} = useSideViewer(null, loadDescriptor);

const selectedFile = computed(
    () => props.mediaFiles.find((m) => m.uuid === selectedUuid.value) ?? null,
);

// Below lg the viewer is a sheet; it opens when a card asks for a preview.
const sheetOpen = ref(false);

function openPreview(uuid: string): void {
    sheetOpen.value = true;

    if (uuid === selectedUuid.value) {
        void refreshViewer();

        return;
    }

    selectFile(uuid);
}

provide(FILE_PREVIEW, {
    oeuvreUuid: props.oeuvre.uuid,
    selectedUuid,
    open: openPreview,
});

function closePreview(): void {
    selectedUuid.value = null;
    viewerDescriptor.value = null;
}

const submitDialogFor = ref<{ uuid: string; label: string } | null>(null);

const openSubmitDialog = () => {
    submitDialogFor.value = { uuid: props.oeuvre.uuid, label: label.value };
};

// What depends on a file's status: the list itself, the quota, the slot
// completeness, and the submission gate (a file reaching `ready` is exactly
// what can unblock "Soumettre"), and under review the replacement window.
const RELOAD_PROPS = [
    'mediaFiles',
    'quota',
    'progress',
    'submission',
    'replacement',
    'review_summary',
    'history',
] as const;

// Under review the ONDA decides each file; the author may only remove a
// rejected one and upload its replacement. Every flag comes from the server.
const underReview = computed(() => props.oeuvre.status === 'under_review');
// The per-file decisions are shown, and fixed, in two statuses: under
// review, and once the whole deposit came back (rejected, editable again).
// Accepted files stay locked in both.
const reviewMode = computed(
    () => underReview.value || props.oeuvre.status === 'rejected',
);
const replacementState = computed(() => props.replacement ?? EMPTY_REPLACEMENT);
const removingUuid = ref<string | null>(null);
// Polite announcements (the dropzone that just opened, …).
const liveMessage = ref('');

const slotTitle = (slotId: number | null): string | null =>
    slotId === null
        ? null
        : (props.requirements.find((r) => r.id === slotId)?.title ?? null);

/**
 * "À corriger": every rejected file still on the deposit, then every slot
 * still waiting for its replacement. Both come from the server's props.
 */
const toFixItems = computed<ToFixItem[]>(() => {
    if (!reviewMode.value) {
        return [];
    }

    const files: ToFixItem[] = props.mediaFiles
        .filter((file) => file.review_status === 'rejected')
        .map((file) => ({
            target: fileAnchor(file.uuid),
            name: file.original_name,
            slot: slotTitle(file.college_oeuvre_file_id),
            kind: 'file',
        }));

    const slots: ToFixItem[] = props.requirements
        .filter((r) => openFor(replacementState.value, r.id) > 0)
        .map((r) => ({
            target: replacementDropzoneId(r.id),
            name: r.title,
            slot: r.title,
            kind: 'slot',
        }));

    return [...files, ...slots];
});

/**
 * Everything the ONDA asked for has been sent: nothing rejected, no slot
 * open, nothing still uploading here, and at least one replacement waiting.
 */
const allSent = computed(
    () =>
        // Under review only: a returned deposit still has to be resubmitted.
        underReview.value &&
        toFixItems.value.length === 0 &&
        replacementState.value.uploading === 0 &&
        !queue.files.value.some(
            (f) =>
                f.oeuvreId === props.oeuvre.id &&
                !['completed', 'failed', 'expired', 'quota_exceeded'].includes(
                    f.status,
                ),
        ) &&
        replacementState.value.pending > 0,
);

/** The last rejection reason of a slot: the reminder above its dropzone. */
function reminderFor(slotId: number): string | null {
    const events = Array.isArray(props.history)
        ? []
        : (props.history?.[String(slotId)] ?? []);

    return (
        [...events].reverse().find((event) => event.kind === 'rejected')
            ?.reason ?? null
    );
}

function slotState(slotId: number): 'to_fix' | 'waiting' | 'accepted' | null {
    const slots = props.review_summary?.slots;

    return !slots || Array.isArray(slots)
        ? null
        : (slots[String(slotId)] ?? null);
}

function historyFor(slotId: number): HistoryEvent[] {
    return Array.isArray(props.history)
        ? []
        : (props.history?.[String(slotId)] ?? []);
}

/** "Aller au fichier": scroll, focus, one soft highlight. */
function goTo(target: string): void {
    revealTarget(target, prefersReducedMotion());
}

// "Remplacer" / "Supprimer" on a rejected file (see createRemovalFlow).
const removal = createRemovalFlow({
    url: (uuid) => destroyFile(uuid).url,
    slotOf: (uuid) =>
        props.mediaFiles.find((file) => file.uuid === uuid)
            ?.college_oeuvre_file_id ?? null,
    reload: (only, after) =>
        router.reload(silent({ ...(only ? { only } : {}), onFinish: after })),
    reloadProps: RELOAD_PROPS,
    focusSlot: (slotId) =>
        void nextTick(() => {
            focusReplacementDropzone(slotId, prefersReducedMotion());
            liveMessage.value = t('oeuvres.replacement.dropzoneReady', {
                slot:
                    props.requirements.find((r) => r.id === slotId)?.title ??
                    '',
            });
        }),
    toast,
    t: (key) => t(key),
});
removal.onBusyChange((uuid) => (removingUuid.value = uuid));

const removeFile = (uuid: string) => {
    // A rejected file (in either status) and anything under review go
    // through the JSON flow, which tells a race with the ONDA apart.
    const rejected =
        props.mediaFiles.find((file) => file.uuid === uuid)?.review_status ===
        'rejected';

    if (underReview.value || rejected) {
        void removal.remove(uuid, false);

        return;
    }

    router.delete(destroyFile(uuid).url, { preserveScroll: true });
};

const replaceFile = (uuid: string) => void removal.remove(uuid, true);

// A notification links to `#file-{uuid}`: bring that file into view.
onMounted(() => {
    const id = window.location.hash.slice(1);

    if (!id.startsWith(fileAnchor(''))) {
        return;
    }

    void nextTick(() =>
        document.getElementById(id)?.scrollIntoView({
            behavior: prefersReducedMotion() ? 'auto' : 'smooth',
            block: 'center',
        }),
    );
});

const readyAtLoadUuids = new Set(
    props.mediaFiles
        .filter((mediaFile) => mediaFile.status === 'ready')
        .map((mediaFile) => mediaFile.uuid),
);

const reloadFileProps = (): void => {
    router.reload(silent({ only: [...RELOAD_PROPS] }));
};

// Follows every file of this page through the server pipeline, live: from
// the upload's `complete` to ready / failed / quarantined. One silent
// partial reload when a file gets there.
const statusPoll = useFileStatusPoll({
    url: () => fileStatus(props.oeuvre.uuid).url,
    files: () => props.mediaFiles,
    onSettled: reloadFileProps,
});

function refreshPage(): void {
    router.reload();
    statusPoll.pollNow();
}

const queue = useUploadQueue();
const completedForThisOeuvre = computed(
    () =>
        queue.files.value.filter(
            (f) => f.oeuvreId === props.oeuvre.id && f.status === 'completed',
        ).length,
);

// An upload just completed: fetch its row, and follow it from now on.
watch(completedForThisOeuvre, (next, previous) => {
    if (next > previous) {
        reloadFileProps();
        statusPoll.kick();
    }
});

// The deposit was decided (or submitted) under an upload: the server
// aborted it. The page itself is stale — reload it into its new state.
const closedForThisOeuvre = computed(
    () =>
        queue.files.value.filter(
            (f) =>
                f.oeuvreId === props.oeuvre.id &&
                f.errorCode !== null &&
                FINAL_UPLOAD_ERRORS.includes(f.errorCode),
        ).length,
);

watch(closedForThisOeuvre, (next, previous) => {
    if (next > previous) {
        router.reload(silent());
    }
});

const entries = computed<DepositEntry[]>(() =>
    mergeDepositEntries(
        queue.files.value,
        props.oeuvre.id,
        props.mediaFiles,
        readyAtLoadUuids,
        statusPoll.live.value,
    ),
);

const hasRequirements = computed(() => props.requirements.length > 0);

const grouped = computed(() =>
    groupEntriesByRequirement(
        entries.value,
        props.requirements.map((requirement) => requirement.id),
    ),
);

const progressPercent = computed(() =>
    props.progress.total === 0
        ? 0
        : Math.round((props.progress.satisfied / props.progress.total) * 100),
);

watch(locale, () => {
    router.reload(silent({ only: ['classification', 'requirements'] }));
});

const pendingResumesForOeuvre = computed(() =>
    queue.pendingResumes.value.filter((f) => f.oeuvreId === props.oeuvre.id),
);

function onReselect(id: string, file: File): void {
    const result = queue.resumeWithReselectedFile(id, file);

    if (!result.ok) {
        // toast handles in global banner
    }
}

const label = computed(() =>
    oeuvreLabel(props.oeuvre, locale.value, t('oeuvres.untitled')),
);

const quotaRemaining = computed(() =>
    Math.max(0, props.quota.limit_bytes - props.quota.used_bytes),
);

const quotaPercent = computed(() => {
    if (!props.quota.limit_bytes) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((props.quota.used_bytes / props.quota.limit_bytes) * 100),
    );
});

const reasonText = (reason: SubmissionReason) =>
    t(`oeuvres.gate.${reason.code}`, {
        name: String(reason.params.name ?? ''),
        status: t(`media.status.${String(reason.params.status ?? 'failed')}`),
    });

// "Modifier et soumettre à nouveau": a rejected deposit is edited on this
// very page, so the action takes the author to their files. Submitting is
// the existing flow, further down, once the gate passes again.
const filesAnchor = ref<HTMLElement | null>(null);

function goToFiles(): void {
    filesAnchor.value?.scrollIntoView({
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
        block: 'start',
    });
}

const statusBadge = computed(() => {
    switch (props.oeuvre.status) {
        case 'registered':
            return {
                label: t('oeuvres.show.status.registered'),
                class: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30',
                dotClass: 'bg-emerald-500',
            };
        case 'submitted':
            return {
                label: t('oeuvres.show.status.submitted'),
                class: 'bg-onda-blue-500/10 text-onda-blue-700 dark:text-onda-blue-300 border-onda-blue-500/30',
                dotClass: 'bg-onda-blue-500 animate-pulse',
            };
        case 'under_review':
            return {
                label: t('oeuvres.show.status.under_review'),
                class: 'bg-purple-500/10 text-purple-700 dark:text-purple-300 border-purple-500/30',
                dotClass: 'bg-purple-500 animate-pulse',
            };
        case 'rejected':
            return {
                label: t('oeuvres.show.status.rejected'),
                class: 'bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-500/30',
                dotClass: 'bg-rose-500',
            };
        case 'draft':
        default:
            return {
                label: t('oeuvres.show.status.draft'),
                class: 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/30',
                dotClass: 'bg-amber-500 animate-pulse',
            };
    }
});
</script>

<template>
    <Head :title="label" />

    <!-- With the viewer open the page uses the whole width; without it the
         content keeps a readable measure. -->
    <div
        class="w-full p-4 sm:p-6 lg:p-8"
        :class="selectedFile ? '' : 'mx-auto max-w-5xl'"
    >
        <SideViewerLayout
            v-model:sheet-open="sheetOpen"
            :active="selectedFile !== null"
            :title="selectedFile?.original_name ?? ''"
            :new-tab-href="
                selectedFile
                    ? preview({
                          oeuvre: oeuvre.uuid,
                          mediaFile: selectedFile.uuid,
                      }).url
                    : null
            "
            closable
            @close="closePreview"
        >
            <div class="space-y-8">
                <!-- Top Hero Navigation & Meta Header -->
                <div class="space-y-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <Link
                            :href="index()"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <ArrowLeft class="size-3.5 rtl:rotate-180" />
                            <span>Retour au catalogue des oeuvres</span>
                        </Link>

                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold"
                                :class="statusBadge.class"
                            >
                                <span
                                    class="size-1.5 rounded-full"
                                    :class="statusBadge.dotClass"
                                />
                                <span>{{ statusBadge.label }}</span>
                            </span>

                            <span
                                v-if="oeuvre.code_college_snapshot"
                                class="rounded-md border border-border/80 bg-muted px-2 py-0.5 font-mono text-[11px] font-semibold text-muted-foreground"
                            >
                                <bdi dir="ltr"
                                    >[{{ oeuvre.code_college_snapshot }}]</bdi
                                >
                            </span>
                        </div>
                    </div>

                    <!-- Title & CTA Bar -->
                    <div
                        class="flex flex-col gap-3 @lg:flex-row @lg:items-center @lg:justify-between"
                    >
                        <div class="min-w-0 space-y-1">
                            <h1
                                class="truncate text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                            >
                                <bdi>{{ label }}</bdi>
                            </h1>
                            <p
                                v-if="oeuvre.description"
                                class="text-sm text-muted-foreground"
                            >
                                {{ oeuvre.description }}
                            </p>
                            <p
                                v-else-if="oeuvre.college_name"
                                class="text-xs text-muted-foreground"
                            >
                                Discipline :
                                <span class="font-medium text-foreground">{{
                                    oeuvre.college_name
                                }}</span>
                                • Créée le
                                {{ formatDate(oeuvre.created_at, locale) }}
                            </p>
                        </div>

                        <!-- Direct Header Submit CTA if ready -->
                        <div
                            v-if="submission.can_submit && editable"
                            class="shrink-0"
                        >
                            <Button
                                class="h-10 cursor-pointer gap-2 bg-emerald-600 px-4 text-xs font-semibold text-white shadow-onda-card hover:bg-emerald-700 dark:bg-emerald-500"
                                @click="openSubmitDialog"
                            >
                                <Send class="size-3.5 rtl:rotate-180" />
                                <span>{{
                                    oeuvre.status === 'rejected'
                                        ? t('oeuvres.gate.resubmit')
                                        : t('oeuvres.table.submit')
                                }}</span>
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Where the deposit stands, and why if it came back. -->
                <DepositStatusBanner
                    :status="oeuvre.status"
                    :registered-at="oeuvre.registered_at"
                    :reviews="reviews"
                    :editable="editable"
                    @resubmit="goToFiles"
                />

                <!-- Under review or returned: what was accepted, what waits,
                     what to fix (the red alert only when something is). -->
                <template v-if="reviewMode && review_summary">
                    <ReviewSummary
                        :accepted="review_summary.accepted"
                        :waiting="review_summary.waiting"
                        :to-fix="review_summary.to_fix"
                    />
                    <ToFixPanel
                        :items="toFixItems"
                        :all-sent="allSent"
                        :last-upload-at="review_summary.last_upload_at"
                        @go="goTo"
                    />
                </template>

                <!-- Official Classification Card -->
                <ClassificationCard
                    v-if="classification"
                    :classification="classification"
                />

                <div
                    ref="filesAnchor"
                    class="pointer-events-none absolute size-0 scroll-mt-24"
                    aria-hidden="true"
                />

                <!-- STEP 2: DOCUMENTS DEPOSIT JOURNEY -->
                <template v-if="hasRequirements">
                    <!-- Modern Bento Stats Card: Documents Progress & Quota Bar -->
                    <div
                        class="grid grid-cols-1 gap-4 @xl:grid-cols-2 @4xl:grid-cols-3"
                    >
                        <!-- 1. Documents Progress Card -->
                        <Card
                            class="border-border/80 shadow-onda-card @xl:col-span-2"
                        >
                            <CardContent
                                class="space-y-3.5 p-5 sm:p-6"
                                data-test="required-progress"
                            >
                                <div
                                    class="flex flex-wrap items-baseline justify-between gap-2"
                                >
                                    <div class="flex items-center gap-2">
                                        <Sparkles
                                            class="size-4 text-onda-blue-600 dark:text-onda-blue-400"
                                        />
                                        <h2
                                            class="text-sm font-semibold tracking-tight text-foreground"
                                        >
                                            {{ t('oeuvres.step2.title') }}
                                        </h2>
                                    </div>

                                    <span
                                        class="font-mono text-xs font-bold text-foreground"
                                    >
                                        {{ progressPercent }}% validé
                                    </span>
                                </div>

                                <!-- Progress Bar -->
                                <div class="space-y-1.5">
                                    <div
                                        class="relative h-2.5 w-full overflow-hidden rounded-full bg-muted shadow-inner"
                                        role="progressbar"
                                        :aria-valuenow="progress.satisfied"
                                        aria-valuemin="0"
                                        :aria-valuemax="progress.total"
                                    >
                                        <div
                                            class="h-full rounded-full bg-gradient-to-r from-onda-blue-600 via-onda-blue-500 to-onda-teal-500 transition-all duration-500 ease-out"
                                            :style="{
                                                width: `${progressPercent}%`,
                                            }"
                                        />
                                    </div>

                                    <div
                                        class="flex items-center justify-between text-xs"
                                    >
                                        <span
                                            class="font-semibold text-foreground tabular-nums"
                                        >
                                            {{
                                                t('oeuvres.step2.progress', {
                                                    satisfied:
                                                        progress.satisfied,
                                                    total: progress.total,
                                                })
                                            }}
                                        </span>
                                        <span
                                            v-if="progress.conditional > 0"
                                            class="text-[11px] text-muted-foreground"
                                        >
                                            {{
                                                t(
                                                    'oeuvres.step2.mayNotApply',
                                                    {
                                                        count: progress.conditional,
                                                    },
                                                    progress.conditional,
                                                )
                                            }}
                                        </span>
                                    </div>
                                </div>

                                <p
                                    class="text-xs leading-relaxed text-muted-foreground"
                                >
                                    {{ t('oeuvres.step2.intro') }}
                                </p>
                            </CardContent>
                        </Card>

                        <!-- 2. Sovereign Quota & Security Card -->
                        <Card class="border-border/80 shadow-onda-card">
                            <CardContent
                                class="flex h-full flex-col justify-between space-y-3.5 p-5 sm:p-6"
                            >
                                <div class="space-y-2">
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div
                                            class="flex items-center gap-2 text-xs font-semibold text-foreground"
                                        >
                                            <HardDrive
                                                class="size-4 text-onda-blue-600 dark:text-onda-blue-400"
                                            />
                                            <span>Espace de stockage</span>
                                        </div>
                                        <span
                                            class="font-mono text-xs font-bold text-foreground"
                                        >
                                            {{ quotaPercent }}%
                                        </span>
                                    </div>

                                    <!-- Mini Quota Bar -->
                                    <div
                                        class="h-2 w-full overflow-hidden rounded-full bg-muted shadow-inner"
                                    >
                                        <div
                                            class="h-full rounded-full transition-all duration-500"
                                            :class="[
                                                quotaPercent > 90
                                                    ? 'bg-rose-500'
                                                    : quotaPercent > 75
                                                      ? 'bg-amber-500'
                                                      : 'bg-onda-blue-600',
                                            ]"
                                            :style="{
                                                width: `${quotaPercent}%`,
                                            }"
                                        />
                                    </div>

                                    <div
                                        class="flex justify-between font-mono text-[11px] text-muted-foreground"
                                    >
                                        <span>{{
                                            formatBytes(
                                                quota.used_bytes,
                                                locale,
                                            )
                                        }}</span>
                                        <span>{{
                                            formatBytes(
                                                quota.limit_bytes,
                                                locale,
                                            )
                                        }}</span>
                                    </div>
                                </div>

                                <!-- Trust lines -->
                                <div
                                    class="space-y-1.5 border-t border-border/60 pt-3 text-[11px] text-muted-foreground"
                                >
                                    <div class="flex items-center gap-2">
                                        <Lock
                                            class="size-3.5 text-emerald-600 dark:text-emerald-400"
                                        />
                                        <span
                                            >Chiffrement au repos AES-256</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <ShieldCheck
                                            class="size-3.5 text-emerald-600 dark:text-emerald-400"
                                        />
                                        <span
                                            >Empreinte cryptographique
                                            certifiée</span
                                        >
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <FileStatusPollNotice
                        :phase="statusPoll.phase.value"
                        @refresh="refreshPage"
                    />

                    <!-- Resume Banner (when interrupted uploads can be recovered) -->
                    <ResumeBanner
                        :files="pendingResumesForOeuvre"
                        @reselect="onReselect"
                    />

                    <!-- Sequence of Required Document Slots -->
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <h2
                                class="text-base font-bold tracking-tight text-foreground"
                            >
                                Pièces exigées pour ce collège ({{
                                    requirements.length
                                }})
                            </h2>
                            <span class="text-xs text-muted-foreground">
                                Déposez les pièces conformément aux
                                spécifications
                            </span>
                        </div>

                        <ol class="space-y-4">
                            <li
                                v-for="(requirement, position) in requirements"
                                :key="requirement.id"
                            >
                                <RequirementSlotCard
                                    :requirement="requirement"
                                    :position="position + 1"
                                    :oeuvre-id="oeuvre.id"
                                    :entries="
                                        grouped.bySlot.get(requirement.id) ?? []
                                    "
                                    :quota="quota"
                                    :editable="editable"
                                    :review-mode="reviewMode"
                                    :replacement-open="
                                        openFor(
                                            replacementState,
                                            requirement.id,
                                        )
                                    "
                                    :busy-uuid="removingUuid"
                                    :review-state="slotState(requirement.id)"
                                    :reminder="reminderFor(requirement.id)"
                                    :history="historyFor(requirement.id)"
                                    @pause="queue.pauseFile"
                                    @resume="queue.resumeFile"
                                    @cancel="queue.cancelFile"
                                    @remove="removeFile"
                                    @replace="replaceFile"
                                />
                            </li>
                        </ol>
                    </div>

                    <!-- Unassigned / Extra Files Section (if any) -->
                    <div
                        v-if="grouped.unassigned.length > 0"
                        class="space-y-3 border-t border-border/80 pt-4"
                    >
                        <div>
                            <h2
                                class="text-sm font-semibold tracking-tight text-foreground"
                            >
                                {{ t('oeuvres.step2.otherFiles') }}
                            </h2>
                            <p class="text-xs text-muted-foreground">
                                {{ t('oeuvres.step2.otherFilesHint') }}
                            </p>
                        </div>
                        <ul class="space-y-3">
                            <li
                                v-for="entry in grouped.unassigned"
                                :key="entryKey(entry)"
                            >
                                <DepositCard
                                    :entry="entry"
                                    :quota="quota"
                                    :editable="editable"
                                    :review-mode="reviewMode"
                                    :busy="
                                        entry.kind === 'media' &&
                                        removingUuid === entry.file.uuid
                                    "
                                    @pause="queue.pauseFile"
                                    @resume="queue.resumeFile"
                                    @cancel="queue.cancelFile"
                                    @remove="removeFile"
                                />
                            </li>
                        </ul>
                    </div>

                    <!-- Global Submit Gate Area -->
                    <SubmitArea
                        :submission="submission"
                        :status="oeuvre.status"
                        :editable="editable"
                        :reason-text="reasonText"
                        @submit="openSubmitDialog"
                    />
                </template>

                <!-- FALLBACK UNCLASSIFIED OEUVRE (Single Flat Dropzone) -->
                <template v-else>
                    <Card class="border-border/80 shadow-onda-card">
                        <CardContent class="space-y-4 p-6">
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <h2
                                    class="text-sm font-semibold text-foreground"
                                >
                                    {{ t('oeuvres.show.addFiles') }}
                                </h2>
                                <i18n-t
                                    keypath="oeuvres.show.quota"
                                    tag="span"
                                    class="text-xs text-muted-foreground"
                                >
                                    <template #used>
                                        <bdi dir="ltr" class="font-mono">{{
                                            formatBytes(
                                                quota.used_bytes,
                                                locale,
                                            )
                                        }}</bdi>
                                    </template>
                                    <template #limit>
                                        <bdi dir="ltr" class="font-mono">{{
                                            formatBytes(
                                                quota.limit_bytes,
                                                locale,
                                            )
                                        }}</bdi>
                                    </template>
                                    <template #remaining>
                                        <bdi
                                            dir="ltr"
                                            class="font-mono font-medium text-foreground"
                                            >{{
                                                formatBytes(
                                                    quotaRemaining,
                                                    locale,
                                                )
                                            }}</bdi
                                        >
                                    </template>
                                </i18n-t>
                            </div>
                            <Dropzone v-if="editable" :oeuvre-id="oeuvre.id" />
                        </CardContent>
                    </Card>

                    <div class="space-y-4">
                        <h2 class="text-sm font-semibold text-foreground">
                            {{ t('oeuvres.show.filesTitle') }}
                        </h2>

                        <ResumeBanner
                            :files="pendingResumesForOeuvre"
                            @reselect="onReselect"
                        />

                        <FileStatusPollNotice
                            :phase="statusPoll.phase.value"
                            @refresh="refreshPage"
                        />

                        <Card
                            v-if="entries.length === 0"
                            class="border-2 border-dashed"
                        >
                            <CardContent
                                class="py-12 text-center text-xs text-muted-foreground"
                            >
                                <i18n-t keypath="oeuvres.show.noFiles" tag="p">
                                    <template #size>
                                        <bdi
                                            dir="ltr"
                                            class="font-mono font-semibold"
                                            >{{
                                                formatBytes(
                                                    MAX_FILE_SIZE_BYTES,
                                                    locale,
                                                )
                                            }}</bdi
                                        >
                                    </template>
                                    <template #extensions>
                                        <bdi dir="ltr" class="font-mono">{{
                                            allowedExtensionList().join(', ')
                                        }}</bdi>
                                    </template>
                                </i18n-t>
                            </CardContent>
                        </Card>

                        <ul v-else class="space-y-3">
                            <li v-for="entry in entries" :key="entryKey(entry)">
                                <DepositCard
                                    :entry="entry"
                                    :quota="quota"
                                    :editable="editable"
                                    :review-mode="reviewMode"
                                    :busy="
                                        entry.kind === 'media' &&
                                        removingUuid === entry.file.uuid
                                    "
                                    @pause="queue.pauseFile"
                                    @resume="queue.resumeFile"
                                    @cancel="queue.cancelFile"
                                    @remove="removeFile"
                                />
                            </li>
                        </ul>

                        <SubmitArea
                            class="mt-6"
                            :submission="submission"
                            :status="oeuvre.status"
                            :editable="editable"
                            :reason-text="reasonText"
                            @submit="openSubmitDialog"
                        />
                    </div>
                </template>

                <!-- Read-only, and signed "ONDA": no officer is named. -->
                <ReviewTimeline
                    v-if="reviews.length > 0"
                    :reviews="reviews"
                    anonymous
                />
            </div>

            <!-- The side Viewer: the owner's own file, read-only. -->
            <template #viewer>
                <ConsultViewer
                    :descriptor="viewerDescriptor"
                    :file-key="selectedUuid ?? ''"
                    compact
                    @refresh="refreshViewer"
                />
            </template>
        </SideViewerLayout>

        <!-- Official Submission Confirmation Dialog -->
        <OeuvreSubmitDialog
            :oeuvre="submitDialogFor"
            @close="submitDialogFor = null"
        />

        <!-- Below sm: "{n} fichier(s) à corriger", to the first one. -->
        <MobileFixBar
            v-if="reviewMode"
            :count="toFixItems.length"
            @go="toFixItems[0] && goTo(toFixItems[0].target)"
        />
        <!-- Leaves room for the bar so it never covers the last file. -->
        <div
            v-if="reviewMode && toFixItems.length > 0"
            class="h-20 sm:hidden"
            aria-hidden="true"
        />

        <p class="sr-only" aria-live="polite" data-testid="live-region">
            {{ liveMessage }}
        </p>
    </div>
</template>
