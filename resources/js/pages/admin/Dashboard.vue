<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard as adminDashboard } from '@/routes/admin';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Admin Dashboard',
                href: adminDashboard(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Admin Dashboard" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">
                Admin Dashboard
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Signed in as {{ user?.name }} ({{ user?.email }})
            </p>
        </div>
    </div>
</template>
