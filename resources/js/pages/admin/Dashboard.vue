<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { FolderOpen, Users } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as authorsIndex } from '@/routes/admin/authors';
import { index as worksIndex } from '@/routes/admin/works';

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

const { t } = useI18n();
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

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Link
                :href="authorsIndex()"
                class="flex items-center gap-3 rounded-lg border border-border bg-card p-4 hover:bg-accent/50"
            >
                <Users class="size-5 text-muted-foreground" />
                <div>
                    <p class="font-medium">{{ t('admin.authors.title') }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ t('admin.authors.subtitle') }}
                    </p>
                </div>
            </Link>
            <Link
                :href="worksIndex()"
                class="flex items-center gap-3 rounded-lg border border-border bg-card p-4 hover:bg-accent/50"
            >
                <FolderOpen class="size-5 text-muted-foreground" />
                <div>
                    <p class="font-medium">{{ t('admin.works.title') }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ t('admin.works.subtitle') }}
                    </p>
                </div>
            </Link>
        </div>
    </div>
</template>
