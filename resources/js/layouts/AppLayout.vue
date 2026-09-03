<script setup lang="ts">
import FullscreenPreloader from '@/components/public/FullscreenPreloader.vue';
import AggregateProgressBar from '@/components/upload/AggregateProgressBar.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <!-- Fullscreen ONDA Logo & Orbital Spinner Loading Screen -->
    <FullscreenPreloader :min-duration="600" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>

    <!--
        Mounted here, not per-page: AppLayout is the persistent layout for
        every non-auth/settings/public page (see app.ts), so this survives
        exactly the navigations an in-flight upload must survive. The
        upload loop itself lives in stores/uploads.ts (a module singleton)
        and runs regardless of whether this is mounted — this only shows
        its progress.
    -->
    <AggregateProgressBar />
</template>
