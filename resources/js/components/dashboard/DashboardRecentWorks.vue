<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Files } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type { DashboardRecent } from '@/components/dashboard/types';
import { oeuvreLabel } from '@/components/oeuvre/label';
import { exactDateTime, relativeTime } from '@/components/review/relativeTime';
import { DataTableStatusBadge } from '@/components/ui/data-table';
import { index, show } from '@/routes/oeuvres';

defineProps<{ recent: DashboardRecent[] }>();

const { t, locale } = useI18n();

const label = (item: DashboardRecent): string =>
    oeuvreLabel(item.label_source, locale.value, t('oeuvres.untitled'));
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-4 shadow-xs sm:p-5"
        aria-labelledby="dashboard-recent-title"
        data-testid="dashboard-recent"
    >
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2
                id="dashboard-recent-title"
                class="flex items-center gap-2 text-sm font-bold tracking-tight text-foreground sm:text-base"
            >
                <Files class="size-4 text-muted-foreground" />
                {{ t('dashboard.recent.title') }}
            </h2>
            <Link
                :href="index()"
                class="rounded text-xs font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
            >
                {{ t('dashboard.recent.all') }}
            </Link>
        </div>

        <ul class="-mx-2 divide-y divide-border/60">
            <li v-for="item in recent" :key="item.uuid">
                <Link
                    :href="show(item.uuid)"
                    class="flex items-center gap-3 rounded-lg px-2 py-2.5 outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
                    data-testid="recent-row"
                >
                    <div class="min-w-0 flex-1 space-y-0.5">
                        <p class="truncate text-sm font-medium text-foreground">
                            <bdi>{{ label(item) }}</bdi>
                        </p>
                        <!-- No reference here: the app has no reference
                             number, and a uuid prefix is a timestamp that two
                             deposits made a minute apart share. The oeuvre
                             page carries the full identifier. -->
                        <p
                            v-if="item.last_activity_at"
                            class="text-[11px] text-muted-foreground"
                            :title="
                                exactDateTime(item.last_activity_at, locale)
                            "
                            data-testid="recent-activity"
                        >
                            {{ t('dashboard.recent.activity') }}
                            <bdi>{{
                                relativeTime(item.last_activity_at, locale)
                            }}</bdi>
                        </p>
                    </div>
                    <DataTableStatusBadge
                        :status="item.status"
                        :label="t(`oeuvres.status.${item.status}`)"
                        class="shrink-0"
                    />
                </Link>
            </li>
        </ul>
    </section>
</template>
