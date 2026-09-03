<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PlusIcon } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { create, index, show } from '@/routes/works';

interface WorkSummary {
    id: number;
    uuid: string;
    title: string;
    status: string;
    created_at: string;
    media_files_count: number;
}

defineProps<{
    works: WorkSummary[];
}>();

const { t } = useI18n();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Works', href: index() }],
    },
});
</script>

<template>
    <Head :title="t('works.index.title')" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 sm:p-6 lg:p-8">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ t('works.index.title') }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ t('works.index.subtitle') }}
                </p>
            </div>
            <Button as-child>
                <Link :href="create()">
                    <PlusIcon />
                    {{ t('works.index.newWork') }}
                </Link>
            </Button>
        </div>

        <Card v-if="works.length === 0">
            <CardContent
                class="py-12 text-center text-sm text-muted-foreground"
            >
                {{ t('works.index.empty') }}
            </CardContent>
        </Card>

        <ul v-else class="space-y-3">
            <li v-for="work in works" :key="work.uuid">
                <Link :href="show(work.uuid)" class="block">
                    <Card class="transition-colors hover:bg-accent/50">
                        <CardContent
                            class="flex items-center justify-between gap-4 py-4"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ work.title }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t('works.index.fileCount', {
                                            count: work.media_files_count,
                                        })
                                    }}
                                </p>
                            </div>
                            <Badge variant="outline">{{
                                t(`works.status.${work.status}`)
                            }}</Badge>
                        </CardContent>
                    </Card>
                </Link>
            </li>
        </ul>
    </div>
</template>
