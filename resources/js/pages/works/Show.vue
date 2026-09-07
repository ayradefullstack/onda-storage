<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Card, CardContent } from '@/components/ui/card';
import DepositCard from '@/components/upload/DepositCard.vue';
import type { DepositEntry } from '@/components/upload/depositJourney';
import {
    entryKey,
    mergeDepositEntries,
} from '@/components/upload/depositJourney';
import Dropzone from '@/components/upload/Dropzone.vue';
import ResumeBanner from '@/components/upload/ResumeBanner.vue';
import { useUploadQueue } from '@/composables/useUploadQueue';
import { formatBytes } from '@/lib/format';
import {
    allowedExtensionList,
    MAX_FILE_SIZE_BYTES,
} from '@/lib/uploadValidation';
import { index } from '@/routes/works';
import type { MediaFileStatus, MediaFileSummary } from '@/types/upload';

interface WorkDetail {
    id: number;
    uuid: string;
    title: string;
    description: string | null;
    status: string;
    created_at: string;
}

interface Quota {
    used_bytes: number;
    limit_bytes: number;
}

const props = defineProps<{
    work: WorkDetail;
    mediaFiles: MediaFileSummary[];
    quota: Quota;
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
const RELOAD_PROPS = ['mediaFiles', 'quota'] as const;
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
// newly-completed upload belonging to this work and reloading once picks
// up the new MediaFile row immediately, instead of waiting for the next
// scheduled poll tick.
const queue = useUploadQueue();
const completedForThisWork = computed(
    () =>
        queue.files.value.filter(
            (f) => f.workId === props.work.id && f.status === 'completed',
        ).length,
);

watch(completedForThisWork, (next, previous) => {
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
        props.work.id,
        props.mediaFiles,
        readyAtLoadUuids,
    ),
);

const pendingResumesForWork = computed(() =>
    queue.pendingResumes.value.filter((f) => f.workId === props.work.id),
);

function onReselect(id: string, file: File): void {
    const result = queue.resumeWithReselectedFile(id, file);

    if (!result.ok) {
        // ResumeBanner in the global sheet already surfaces the same
        // failure via a toast; this inline copy stays quiet on success.
    }
}

const quotaRemaining = computed(() =>
    Math.max(0, props.quota.limit_bytes - props.quota.used_bytes),
);
</script>

<template>
    <Head :title="work.title" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6 lg:p-8">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">
                {{ work.title }}
            </h1>
            <p
                v-if="work.description"
                class="mt-1 text-sm text-muted-foreground"
            >
                {{ work.description }}
            </p>
        </div>

        <Card>
            <CardContent class="space-y-3 py-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-medium">
                        {{ t('works.show.addFiles') }}
                    </h2>
                    <i18n-t
                        keypath="works.show.quota"
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
                <Dropzone :work-id="work.id" />
            </CardContent>
        </Card>

        <div>
            <h2 class="mb-3 text-sm font-medium">
                {{ t('works.show.filesTitle') }}
            </h2>

            <ResumeBanner
                :files="pendingResumesForWork"
                @reselect="onReselect"
            />

            <Card v-if="entries.length === 0">
                <CardContent class="py-10 text-center text-sm">
                    <i18n-t
                        keypath="works.show.noFiles"
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
                        @pause="queue.pauseFile"
                        @resume="queue.resumeFile"
                        @cancel="queue.cancelFile"
                    />
                </li>
            </ul>
        </div>
    </div>
</template>
