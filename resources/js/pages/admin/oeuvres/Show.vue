<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MediaFileInspectionCard from '@/components/admin/MediaFileInspectionCard.vue';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import StreamOriginalDialog from '@/components/admin/StreamOriginalDialog.vue';
import { oeuvreLabel } from '@/components/oeuvre/label';
import { formatDate } from '@/lib/format';
import { dashboard } from '@/routes/admin';
import { show as authorShow } from '@/routes/admin/authors';
import { index as oeuvresIndex } from '@/routes/admin/oeuvres';

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

const streamDialogOpen = ref(false);
const streamTarget = ref<MediaFileDetail | null>(null);

function openStreamDialog(file: MediaFileDetail): void {
    streamTarget.value = file;
    streamDialogOpen.value = true;
}
</script>

<template>
    <Head :title="label" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-6">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-semibold tracking-tight">
                    <bdi>{{ label }}</bdi>
                </h1>
                <StatusBadge kind="oeuvre" :status="oeuvre.status" />
            </div>
            <p
                v-if="oeuvre.description"
                class="mt-1 text-sm text-muted-foreground"
            >
                {{ oeuvre.description }}
            </p>
            <p class="mt-2 text-sm">
                <Link
                    v-if="oeuvre.author"
                    :href="authorShow(oeuvre.author.uuid)"
                    class="hover:underline"
                >
                    {{ oeuvre.author.name }}
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

        <div class="space-y-4">
            <h2 class="text-sm font-medium">
                {{ t('admin.oeuvres.filesTitle') }}
            </h2>

            <p v-if="files.length === 0" class="text-sm text-muted-foreground">
                {{ t('admin.oeuvres.noFiles') }}
            </p>

            <MediaFileInspectionCard
                v-for="file in files"
                :key="file.uuid"
                :file="file"
                @stream-original="openStreamDialog(file)"
            />
        </div>

        <StreamOriginalDialog
            v-model:open="streamDialogOpen"
            :file="streamTarget"
        />
    </div>
</template>
