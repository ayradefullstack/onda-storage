<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, FileEdit } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/admin/EmptyState.vue';
import MediaFileInspectionCard from '@/components/admin/MediaFileInspectionCard.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import ConsultViewer from '@/components/consult/ConsultViewer.vue';
import SideViewerLayout from '@/components/consult/SideViewerLayout.vue';
import { useSideViewer } from '@/components/consult/useSideViewer';
import { oeuvreLabel } from '@/components/oeuvre/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { formatDate, truncateFilenameMiddle } from '@/lib/format';
import { dashboard } from '@/routes/admin';
import { show as authorShow } from '@/routes/admin/authors';
import { index as oeuvresIndex } from '@/routes/admin/oeuvres';
import { review } from '@/routes/admin/oeuvres/files';
import { assets as reviewAssets } from '@/routes/admin/oeuvres/files/review';
import type { ConsultationDescriptor } from '@/types/consultation';

interface MediaFileDetail {
    uuid: string;
    original_name: string;
    extension: string;
    mime: string;
    size_bytes: number;
    status: string;
    duration_sec: number | null;
    width: number | null;
    height: number | null;
    sha256_plain: string | null;
    scan_result: 'clean' | 'infected' | 'pending' | 'unknown';
    scanned_at: string | null;
    verified_at: string | null;
    created_at: string;
    variants: string[];
}

const props = defineProps<{
    oeuvre: {
        uuid: string;
        title: string | null;
        college_name: string | null;
        description: string | null;
        status: string;
        author: { uuid: string; name: string } | null;
        created_at: string;
        registered_at: string | null;
        is_draft?: boolean;
    };
    files: MediaFileDetail[];
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

const label = computed(() =>
    oeuvreLabel(props.oeuvre, locale.value, t('oeuvres.untitled')),
);

// The side Viewer: selection state and the stale-reply guard live in
// useSideViewer (unit-tested); this only supplies how a descriptor is fetched.
async function loadDescriptor(
    uuid: string,
): Promise<ConsultationDescriptor | null> {
    try {
        const response = await fetch(
            reviewAssets({ oeuvre: props.oeuvre.uuid, mediaFile: uuid }).url,
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
        // Network blip: the next poll or selection tries again.
        return null;
    }
}

const { selectedUuid, descriptor, select, refresh } = useSideViewer(
    props.files[0]?.uuid ?? null,
    loadDescriptor,
);

onMounted(refresh);

const selectedFile = computed(
    () => props.files.find((file) => file.uuid === selectedUuid.value) ?? null,
);

// Below lg the viewer is a sheet: it opens on a tap, not on page load.
const sheetOpen = ref(false);

function show(uuid: string): void {
    select(uuid);
    sheetOpen.value = true;
}

function reviewUrl(file: MediaFileDetail): string {
    return review({ oeuvre: props.oeuvre.uuid, mediaFile: file.uuid }).url;
}
</script>

<template>
    <Head :title="label" />

    <div class="w-full space-y-6 p-4 md:p-6">
        <Alert v-if="oeuvre.is_draft" role="status">
            <FileEdit />
            <AlertDescription>
                {{ t('admin.oeuvres.draftBanner') }}
            </AlertDescription>
        </Alert>

        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-semibold tracking-tight">
                    <bdi dir="ltr">{{ label }}</bdi>
                </h1>
                <StatusBadge kind="oeuvre" :status="oeuvre.status" />
            </div>
            <p
                v-if="oeuvre.description"
                class="mt-1 max-w-3xl text-sm text-muted-foreground"
            >
                {{ oeuvre.description }}
            </p>
            <p class="mt-2 text-sm">
                <Link
                    v-if="oeuvre.author"
                    :href="authorShow(oeuvre.author.uuid)"
                    class="hover:underline"
                >
                    <bdi>{{ oeuvre.author.name }}</bdi>
                </Link>
                <span class="text-muted-foreground">
                    ·
                    <bdi dir="ltr">{{
                        formatDate(oeuvre.created_at, locale)
                    }}</bdi>
                    <template v-if="oeuvre.registered_at">
                        · {{ t('admin.oeuvres.registeredOn') }}
                        <bdi dir="ltr">{{
                            formatDate(oeuvre.registered_at, locale)
                        }}</bdi>
                    </template>
                </span>
            </p>
        </div>

        <EmptyState
            v-if="files.length === 0"
            :title="t('admin.oeuvres.noFiles')"
        />

        <SideViewerLayout
            v-else
            v-model:sheet-open="sheetOpen"
            :active="selectedFile !== null"
            :title="selectedFile?.original_name ?? ''"
            :new-tab-href="selectedFile ? reviewUrl(selectedFile) : null"
        >
            <div class="space-y-4">
                <h2 class="text-sm font-medium">
                    {{ t('admin.oeuvres.filesTitle') }}
                    <span class="text-muted-foreground tabular-nums"
                        >({{ files.length }})</span
                    >
                </h2>

                <ul class="space-y-1" data-testid="file-list">
                    <li
                        v-for="(file, i) in files"
                        :key="file.uuid"
                        class="motion-fade-up flex items-center gap-1 rounded-md border transition-colors"
                        :class="
                            file.uuid === selectedUuid
                                ? 'border-primary bg-accent'
                                : 'border-border hover:bg-accent/50'
                        "
                        :style="{ '--motion-index': i }"
                        :data-selected="file.uuid === selectedUuid"
                    >
                        <button
                            type="button"
                            class="min-w-0 flex-1 cursor-pointer truncate rounded-md px-3 py-2 text-start text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                            :aria-current="file.uuid === selectedUuid"
                            :title="file.original_name"
                            @click="show(file.uuid)"
                        >
                            <bdi dir="ltr">{{
                                truncateFilenameMiddle(file.original_name, 44)
                            }}</bdi>
                        </button>
                        <a
                            :href="reviewUrl(file)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="shrink-0 rounded-md p-2 text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/40"
                            :title="t('consult.openInNewTab')"
                            :aria-label="t('consult.openInNewTab')"
                        >
                            <ExternalLink class="size-4" />
                        </a>
                    </li>
                </ul>

                <!-- Facts of the file shown in the viewer, and only that one. -->
                <MediaFileInspectionCard
                    v-if="selectedFile"
                    :key="selectedFile.uuid"
                    :file="selectedFile"
                />
            </div>

            <template #viewer>
                <ConsultViewer
                    :descriptor="descriptor"
                    :file-key="selectedUuid ?? ''"
                    compact
                    @refresh="refresh"
                />
            </template>
        </SideViewerLayout>
    </div>
</template>
