<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Ban, Gavel, SearchCheck, ShieldCheck } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type { DashboardDecision } from '@/components/dashboard/types';
import { oeuvreLabel } from '@/components/oeuvre/label';
import { exactDateTime, relativeTime } from '@/components/review/relativeTime';
import { show } from '@/routes/oeuvres';

defineProps<{ decisions: DashboardDecision[] }>();

const { t, locale } = useI18n();

const label = (item: DashboardDecision): string =>
    oeuvreLabel(item.label_source, locale.value, t('oeuvres.untitled'));

// The same icons and tones as the notification bell, for the same events.
const ICONS = {
    under_review: SearchCheck,
    registered: ShieldCheck,
    rejected: Ban,
} as const;

const ICON_CLASS = {
    under_review:
        'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400',
    registered:
        'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400',
    rejected: 'bg-destructive/10 text-destructive',
} as const;
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-4 shadow-xs sm:p-5"
        aria-labelledby="dashboard-decisions-title"
        data-testid="dashboard-decisions"
    >
        <h2
            id="dashboard-decisions-title"
            class="mb-3 flex items-center gap-2 text-sm font-bold tracking-tight text-foreground sm:text-base"
        >
            <Gavel class="size-4 text-muted-foreground" />
            {{ t('dashboard.decisions.title') }}
        </h2>

        <ul class="-mx-2 divide-y divide-border/60">
            <li v-for="item in decisions" :key="item.uuid">
                <Link
                    :href="show(item.oeuvre_uuid)"
                    class="flex items-start gap-3 rounded-lg px-2 py-2.5 outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
                    :data-testid="`decision-${item.to_status}`"
                >
                    <span
                        :class="[
                            'flex size-8 shrink-0 items-center justify-center rounded-lg',
                            ICON_CLASS[item.to_status],
                        ]"
                    >
                        <component :is="ICONS[item.to_status]" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-0.5">
                        <p class="truncate text-sm font-medium text-foreground">
                            <bdi>{{ label(item) }}</bdi>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ t(`dashboard.decisions.${item.to_status}`) }}
                        </p>
                    </div>
                    <span
                        v-if="item.at"
                        class="shrink-0 text-[11px] text-muted-foreground"
                        :title="exactDateTime(item.at, locale)"
                    >
                        <bdi>{{ relativeTime(item.at, locale) }}</bdi>
                    </span>
                </Link>
            </li>
        </ul>
    </section>
</template>
