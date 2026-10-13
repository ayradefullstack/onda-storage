<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import FileReviewLayout from '@/components/consult/FileReviewLayout.vue';
import { index, show } from '@/routes/oeuvres';
import { preview } from '@/routes/oeuvres/files';
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

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Works', href: index() }],
    },
});

// A thin wrapper around the shared layout: the author sees Preview and
// Details only. The activity trail is admin-only and is not even sent.
const fileHref = (uuid: string): string =>
    preview({ oeuvre: props.oeuvre.uuid, mediaFile: uuid }).url;
</script>

<template>
    <Head :title="file.original_name" />

    <FileReviewLayout
        :oeuvre="oeuvre"
        :file="file"
        :siblings="siblings"
        :consultation="consultation"
        :back-href="show(oeuvre.uuid).url"
        :file-href="fileHref"
        details
    />
</template>
