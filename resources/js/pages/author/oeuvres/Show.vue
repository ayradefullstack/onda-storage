<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { LockIcon, ShieldCheckIcon } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ClassificationCard from '@/components/oeuvre/ClassificationCard.vue';
import type { OeuvreClassification } from '@/components/oeuvre/ClassificationCard.vue';
import { oeuvreLabel } from '@/components/oeuvre/label';
import OeuvreSubmitDialog from '@/components/oeuvre/OeuvreSubmitDialog.vue';
import SubmitArea from '@/components/oeuvre/SubmitArea.vue';
import { Card, CardContent } from '@/components/ui/card';
import DepositCard from '@/components/upload/DepositCard.vue';
import type { DepositEntry } from '@/components/upload/depositJourney';
import {
    entryKey,
    groupEntriesByRequirement,
    mergeDepositEntries,
} from '@/components/upload/depositJourney';
import Dropzone from '@/components/upload/Dropzone.vue';
import type { RequirementSlot } from '@/components/upload/requirement';
import RequirementSlotCard from '@/components/upload/RequirementSlotCard.vue';
import ResumeBanner from '@/components/upload/ResumeBanner.vue';
import { useUploadQueue } from '@/composables/useUploadQueue';
import { formatBytes } from '@/lib/format';
import {
    allowedExtensionList,
    MAX_FILE_SIZE_BYTES,
} from '@/lib/uploadValidation';
import { index } from '@/routes/oeuvres';
import { destroy as destroyFile } from '@/routes/oeuvres/files';
import type { MediaFileStatus, MediaFileSummary } from '@/types/upload';

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
    /** OeuvrePolicy's answer, not the template's guess. */
    can: { edit: boolean; delete: boolean };
}

/** One blocker or advisory from SubmissionGate. */
interface SubmissionReason {
    code: string;
    params: Record<string, string | number | null>;
    /** English source string — the UI renders `code` instead. */
    message: string;
}

interface Submission {
    can_submit: boolean;
    /** Every reason at once, never just the first one found. */
    blockers: SubmissionReason[];
    /** Empty CONDITIONAL required slots. Advisory; they never block. */
    advisories: SubmissionReason[];
    /** False once the deposit is frozen — neither button nor blockers apply. */
    is_open: boolean;
}

interface Quota {
    used_bytes: number;
    limit_bytes: number;
}

/** `Oeuvre::requiredDocumentsProgress()` — counts only files at `ready`. */
interface RequiredDocumentsProgress {
    satisfied: number;
    total: number;
    /** Unsatisfied required slots whose (unevaluated) condition may exclude them. */
    conditional: number;
}

const props = defineProps<{
    oeuvre: OeuvreDetail;
    classification: OeuvreClassification | null;
    mediaFiles: MediaFileSummary[];
    quota: Quota;
    /** One upload slot per required document; empty for an unclassified oeuvre. */
    requirements: RequirementSlot[];
    progress: RequiredDocumentsProgress;
    submission: Submission;
}>();

const { t, locale } = useI18n();

// defineOptions()'s argument is hoisted out of setup() at compile time, so
// it cannot reference `props` (a runtime value) — only a static breadcrumb
// is possible here, unlike Index/Create which have no dynamic segment.
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Works', href: index() }],
    },
});

// --- the submit area. `editable` is the policy's answer, carried on the
// oeuvre prop; everything the page offers keys off it, and the server
// refuses regardless (OeuvrePolicy, InitUpload's status guard).
const editable = computed(() => props.oeuvre.can.edit);

const submitDialogFor = ref<{ uuid: string; label: string } | null>(null);

const openSubmitDialog = () => {
    submitDialogFor.value = { uuid: props.oeuvre.uuid, label: label.value };
};

/**
 * Taking a file off the deposit. The confirmation already happened inside
 * DepositCard; this only issues the request. `preserveScroll` so a long
 * slot list does not jump back to the top on every removal.
 */
const removeFile = (uuid: string) => {
    router.delete(destroyFile(uuid).url, { preserveScroll: true });
};

// A deposit that was already `ready` when this page loaded collapses to
// just the seal — its journey isn't news. One reached during this visit
// stays on the full rail for the rest of the visit. Captured once, at
// setup time, deliberately not reactive to the polling reloads below.
const readyAtLoadUuids = new Set(
    props.mediaFiles
        .filter((mediaFile) => mediaFile.status === 'ready')
        .map((mediaFile) => mediaFile.uuid),
);

// --- status polling: backs off 2s -> 5s -> 15s, stops once every file has
// reached a terminal state. Reuses the Show route itself via Inertia's
// partial-reload mechanism rather than a bespoke JSON endpoint. `quota` is
// included every time — a partial reload only refreshes the props named
// here, so a figure completed uploads charge against would otherwise go
// stale until a full page reload.
// `progress` rides along: it only moves when a file reaches `ready`, which
// is exactly what these reloads detect.
const RELOAD_PROPS = ['mediaFiles', 'quota', 'progress'] as const;
const TERMINAL_STATUSES: MediaFileStatus[] = ['ready', 'failed', 'quarantined'];
const POLL_INTERVALS_MS = [2000, 5000, 15000];

let pollTimer: ReturnType<typeof setTimeout> | null = null;
let pollAttempt = 0;

function hasNonTerminalFiles(): boolean {
    return props.mediaFiles.some(
        (mediaFile) => !TERMINAL_STATUSES.includes(mediaFile.status),
    );
}

function schedulePoll(): void {
    if (!hasNonTerminalFiles()) {
        return;
    }

    const delay =
        POLL_INTERVALS_MS[Math.min(pollAttempt, POLL_INTERVALS_MS.length - 1)];

    pollTimer = setTimeout(() => {
        router.reload({
            only: [...RELOAD_PROPS],
            onFinish: () => {
                pollAttempt++;
                schedulePoll();
            },
        });
    }, delay);
}

onMounted(() => {
    pollAttempt = 0;
    schedulePoll();
});

onBeforeUnmount(() => {
    if (pollTimer) {
        clearTimeout(pollTimer);
    }
});

// A file completing (P3's `complete`) doesn't itself refresh this page's
// props — the upload store and this page are independent. Watching for a
// newly-completed upload belonging to this oeuvre and reloading once picks
// up the new MediaFile row immediately, instead of waiting for the next
// scheduled poll tick.
const queue = useUploadQueue();
const completedForThisOeuvre = computed(
    () =>
        queue.files.value.filter(
            (f) => f.oeuvreId === props.oeuvre.id && f.status === 'completed',
        ).length,
);

watch(completedForThisOeuvre, (next, previous) => {
    if (next > previous) {
        router.reload({ only: [...RELOAD_PROPS] });
    }
});

// --- the merged deposit list: an in-flight upload and its eventual
// MediaFile row are the same file, one row, never disappearing and
// reappearing. See `mergeDepositEntries` for why `completed` uploads are
// excluded.
const entries = computed<DepositEntry[]>(() =>
    mergeDepositEntries(
        queue.files.value,
        props.oeuvre.id,
        props.mediaFiles,
        readyAtLoadUuids,
    ),
);

// Step 2: a classified oeuvre gets one card per required document; an
// unclassified one keeps the single dropzone and flat list below.
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

// Collège, classification and requirement titles are resolved server-side in
// the request locale.
watch(locale, () => {
    router.reload({ only: ['classification', 'requirements'] });
});

const pendingResumesForOeuvre = computed(() =>
    queue.pendingResumes.value.filter((f) => f.oeuvreId === props.oeuvre.id),
);

function onReselect(id: string, file: File): void {
    const result = queue.resumeWithReselectedFile(id, file);

    if (!result.ok) {
        // ResumeBanner in the global sheet already surfaces the same
        // failure via a toast; this inline copy stays quiet on success.
    }
}

const label = computed(() =>
    oeuvreLabel(props.oeuvre, locale.value, t('oeuvres.untitled')),
);

const quotaRemaining = computed(() =>
    Math.max(0, props.quota.limit_bytes - props.quota.used_bytes),
);

/**
 * A gate reason, rendered in the reader's language. The gate emits a
 * stable `code` plus parameters rather than a sentence — this project has
 * no server-side `lang/`, so the prose lives in the locale files and the
 * server stays language-agnostic (see SubmissionReason).
 */
const reasonText = (reason: SubmissionReason) =>
    t(`oeuvres.gate.${reason.code}`, {
        name: String(reason.params.name ?? ''),
        status: t(`media.status.${String(reason.params.status ?? 'failed')}`),
    });

</script>

<template>
    <Head :title="label" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6 lg:p-8">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                <bdi>{{ label }}</bdi>
            </h1>
            <p
                v-if="oeuvre.description"
                class="mt-1 text-sm text-muted-foreground"
            >
                {{ oeuvre.description }}
            </p>
        </div>

        <ClassificationCard
            v-if="classification"
            :classification="classification"
        />

        <template v-if="hasRequirements">
            <Card>
                <CardContent class="space-y-3 py-6">
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-3"
                    >
                        <h2 class="text-sm font-medium">
                            {{ t('oeuvres.step2.title') }}
                        </h2>
                        <i18n-t
                            keypath="oeuvres.show.quota"
                            tag="span"
                            class="text-xs text-muted-foreground"
                        >
                            <template #used
                                ><bdi dir="ltr">{{
                                    formatBytes(quota.used_bytes, locale)
                                }}</bdi></template
                            >
                            <template #limit
                                ><bdi dir="ltr">{{
                                    formatBytes(quota.limit_bytes, locale)
                                }}</bdi></template
                            >
                            <template #remaining
                                ><bdi dir="ltr">{{
                                    formatBytes(quotaRemaining, locale)
                                }}</bdi></template
                            >
                        </i18n-t>
                    </div>

                    <div class="space-y-2" data-test="required-progress">
                        <p class="text-sm">
                            <span class="font-semibold tabular-nums">{{
                                t('oeuvres.step2.progress', {
                                    satisfied: progress.satisfied,
                                    total: progress.total,
                                })
                            }}</span>
                            <span
                                v-if="progress.conditional > 0"
                                class="text-muted-foreground"
                            >
                                —
                                {{
                                    t(
                                        'oeuvres.step2.mayNotApply',
                                        { count: progress.conditional },
                                        progress.conditional,
                                    )
                                }}</span
                            >
                        </p>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-valuenow="progress.satisfied"
                            aria-valuemin="0"
                            :aria-valuemax="progress.total"
                        >
                            <div
                                class="h-full rounded-full bg-primary transition-[width] duration-500"
                                :style="{ width: progressPercent + '%' }"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ t('oeuvres.step2.intro') }}
                        </p>
                    </div>

                    <div class="space-y-1 text-xs text-muted-foreground">
                        <p class="flex items-center gap-1.5">
                            <LockIcon class="size-3.5 shrink-0" />
                            {{ t('upload.trust.encrypted') }}
                        </p>
                        <p class="flex items-center gap-1.5">
                            <ShieldCheckIcon class="size-3.5 shrink-0" />
                            {{ t('upload.trust.fingerprint') }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <ResumeBanner
                :files="pendingResumesForOeuvre"
                @reselect="onReselect"
            />

            <ol class="space-y-4">
                <li
                    v-for="(requirement, position) in requirements"
                    :key="requirement.id"
                >
                    <RequirementSlotCard
                        :requirement="requirement"
                        :position="position + 1"
                        :oeuvre-id="oeuvre.id"
                        :entries="grouped.bySlot.get(requirement.id) ?? []"
                        :quota="quota"
                        :editable="editable"
                        @pause="queue.pauseFile"
                        @resume="queue.resumeFile"
                        @cancel="queue.cancelFile"
                        @remove="removeFile"
                    />
                </li>
            </ol>

            <SubmitArea
                :submission="submission"
                :status="oeuvre.status"
                :editable="editable"
                :reason-text="reasonText"
                @submit="openSubmitDialog"
            />

            <div v-if="grouped.unassigned.length > 0">
                <h2 class="text-sm font-medium">
                    {{ t('oeuvres.step2.otherFiles') }}
                </h2>
                <p class="mb-3 text-xs text-muted-foreground">
                    {{ t('oeuvres.step2.otherFilesHint') }}
                </p>
                <ul class="space-y-3">
                    <li
                        v-for="entry in grouped.unassigned"
                        :key="entryKey(entry)"
                    >
                        <DepositCard
                            :entry="entry"
                            :quota="quota"
                            :editable="editable"
                            @pause="queue.pauseFile"
                            @resume="queue.resumeFile"
                            @cancel="queue.cancelFile"
                            @remove="removeFile"
                        />
                    </li>
                </ul>
            </div>
        </template>

        <template v-else>
            <Card>
                <CardContent class="space-y-3 py-6">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-medium">
                            {{ t('oeuvres.show.addFiles') }}
                        </h2>
                        <i18n-t
                            keypath="oeuvres.show.quota"
                            tag="span"
                            class="text-xs text-muted-foreground"
                        >
                            <template #used
                                ><bdi dir="ltr">{{
                                    formatBytes(quota.used_bytes, locale)
                                }}</bdi></template
                            >
                            <template #limit
                                ><bdi dir="ltr">{{
                                    formatBytes(quota.limit_bytes, locale)
                                }}</bdi></template
                            >
                            <template #remaining
                                ><bdi dir="ltr">{{
                                    formatBytes(quotaRemaining, locale)
                                }}</bdi></template
                            >
                        </i18n-t>
                    </div>
                    <Dropzone v-if="editable" :oeuvre-id="oeuvre.id" />
                </CardContent>
            </Card>

            <div>
                <h2 class="mb-3 text-sm font-medium">
                    {{ t('oeuvres.show.filesTitle') }}
                </h2>

                <ResumeBanner
                    :files="pendingResumesForOeuvre"
                    @reselect="onReselect"
                />

                <Card v-if="entries.length === 0">
                    <CardContent class="py-10 text-center text-sm">
                        <i18n-t
                            keypath="oeuvres.show.noFiles"
                            tag="p"
                            class="text-muted-foreground"
                        >
                            <template #size
                                ><bdi dir="ltr">{{
                                    formatBytes(MAX_FILE_SIZE_BYTES, locale)
                                }}</bdi></template
                            >
                            <template #extensions
                                ><bdi dir="ltr">{{
                                    allowedExtensionList().join(', ')
                                }}</bdi></template
                            >
                        </i18n-t>
                    </CardContent>
                </Card>

                <ul v-else class="space-y-3">
                    <li v-for="entry in entries" :key="entryKey(entry)">
                        <DepositCard
                            :entry="entry"
                            :quota="quota"
                            :editable="editable"
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

        <OeuvreSubmitDialog
            :oeuvre="submitDialogFor"
            @close="submitDialogFor = null"
        />
    </div>
</template>
