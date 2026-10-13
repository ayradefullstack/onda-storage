<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import FileReviewLayout from '@/components/consult/FileReviewLayout.vue';
import { dashboard } from '@/routes/admin';
import {
    index as oeuvresIndex,
    show as oeuvreShow,
} from '@/routes/admin/oeuvres';
import { review } from '@/routes/admin/oeuvres/files';
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
        breadcrumbs: [
            { title: 'Admin', href: dashboard() },
            { title: 'Works', href: oeuvresIndex() },
        ],
    },
});

// A thin wrapper: the page body is shared with the author's own preview.
const fileHref = (uuid: string): string =>
    review({ oeuvre: props.oeuvre.uuid, mediaFile: uuid }).url;
</script>

<template>
    <Head :title="file.original_name" />

    <FileReviewLayout
        :oeuvre="oeuvre"
        :file="file"
        :siblings="siblings"
        :consultation="consultation"
        :back-href="oeuvreShow(oeuvre.uuid).url"
        :file-href="fileHref"
    />
</template>
