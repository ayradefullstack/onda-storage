<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FileIcon } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import StatusBadge from '@/components/media/StatusBadge.vue';
import { Card, CardContent } from '@/components/ui/card';
import Dropzone from '@/components/upload/Dropzone.vue';
import { useUploadQueue } from '@/composables/useUploadQueue';
import { formatBytes, formatDuration } from '@/lib/format';
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

const props = defineProps<{
    work: WorkDetail;
    mediaFiles: MediaFileSummary[];
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

// --- status polling: backs off 2s -> 5s -> 15s, stops once every file has
// reached a terminal state. Reuses the Show route itself via Inertia's
// partial-reload mechanism rather than a bespoke JSON endpoint.
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
            only: ['mediaFiles'],
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
        router.reload({ only: ['mediaFiles'] });
    }
});

function dimensions(mediaFile: MediaFileSummary): string | null {
    return mediaFile.width && mediaFile.height
        ? `${mediaFile.width}×${mediaFile.height}`
        : null;
}
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
            <CardContent class="py-6">
                <h2 class="mb-3 text-sm font-medium">
                    {{ t('works.show.addFiles') }}
                </h2>
                <Dropzone :work-id="work.id" />
            </CardContent>
        </Card>

        <div>
            <h2 class="mb-3 text-sm font-medium">
                {{ t('works.show.filesTitle') }}
            </h2>

            <Card v-if="mediaFiles.length === 0">
                <CardContent
                    class="py-10 text-center text-sm text-muted-foreground"
                >
                    {{ t('works.show.noFiles') }}
                </CardContent>
            </Card>

            <ul v-else class="space-y-3">
                <li v-for="mediaFile in mediaFiles" :key="mediaFile.uuid">
                    <Card>
                        <CardContent class="flex items-center gap-4 py-4">
                            <FileIcon
                                class="size-8 shrink-0 text-muted-foreground"
                            />

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ mediaFile.original_name }}
                                </p>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground"
                                >
                                    <span>{{
                                        formatBytes(
                                            mediaFile.size_bytes,
                                            locale,
                                        )
                                    }}</span>
                                    <span
                                        v-if="mediaFile.duration_sec !== null"
                                        >{{
                                            formatDuration(
                                                mediaFile.duration_sec,
                                            )
                                        }}</span
                                    >
                                    <span v-if="dimensions(mediaFile)">{{
                                        dimensions(mediaFile)
                                    }}</span>
                                    <span v-if="mediaFile.variant_count > 0">
                                        {{
                                            t('works.show.variantCount', {
                                                count: mediaFile.variant_count,
                                            })
                                        }}
                                    </span>
                                </div>

                                <p
                                    v-if="mediaFile.status === 'quarantined'"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ t('media.quarantineExplain') }}
                                </p>
                                <p
                                    v-else-if="mediaFile.status === 'failed'"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ t('media.failedExplain') }}
                                </p>
                            </div>

                            <StatusBadge :status="mediaFile.status" />
                        </CardContent>
                    </Card>
                </li>
            </ul>
        </div>
    </div>
</template>
