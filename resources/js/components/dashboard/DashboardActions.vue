<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    CircleDashed,
    Clock,
    ListTodo,
    RefreshCcw,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import type {
    DashboardAction,
    DashboardActions,
    GateReason,
} from '@/components/dashboard/types';
import { oeuvreLabel } from '@/components/oeuvre/label';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { index, show } from '@/routes/oeuvres';

defineProps<{ actions: DashboardActions }>();

const { t, locale } = useI18n();

const label = (item: DashboardAction): string =>
    oeuvreLabel(item.label_source, locale.value, t('oeuvres.untitled'));

/** Same wording as the oeuvre page's submit area (author Show.vue). */
const reasonText = (reason: GateReason): string =>
    t(`oeuvres.gate.${reason.code}`, {
        name: String(reason.params.name ?? ''),
        status: t(`media.status.${String(reason.params.status ?? 'failed')}`),
    });

const ICONS = {
    rejected: CircleAlert,
    incomplete: CircleDashed,
    ready: CircleCheck,
    replace: RefreshCcw,
    awaiting: Clock,
} as const;

const ICON_CLASS = {
    rejected: 'bg-destructive/10 text-destructive',
    incomplete:
        'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400',
    ready: 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400',
    replace: 'bg-destructive/10 text-destructive',
    awaiting: 'bg-muted text-muted-foreground',
} as const;

// Every action opens the oeuvre page: that is where the files are added,
// the full list of problems is shown and the submission is confirmed.
const ACTION_KEY = {
    rejected: 'dashboard.actions.fix',
    incomplete: 'dashboard.actions.complete',
    ready: 'dashboard.actions.submit',
    replace: 'dashboard.actions.replace',
    awaiting: 'dashboard.actions.view',
} as const;
</script>

<template>
    <section
        class="rounded-xl border border-border bg-card p-4 shadow-xs sm:p-5"
        aria-labelledby="dashboard-actions-title"
        data-testid="dashboard-actions"
    >
        <div class="mb-3 flex items-center justify-between gap-2">
            <h2
                id="dashboard-actions-title"
                class="flex items-center gap-2 text-sm font-bold tracking-tight text-foreground sm:text-base"
            >
                <ListTodo class="size-4 text-muted-foreground" />
                {{ t('dashboard.actions.title') }}
            </h2>
            <Link
                v-if="actions.total > actions.items.length"
                :href="index()"
                class="rounded text-xs font-medium text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                data-testid="actions-more"
            >
                {{ t('dashboard.actions.more', { count: actions.total }) }}
            </Link>
        </div>

        <ul class="divide-y divide-border/60">
            <li
                v-for="item in actions.items"
                :key="item.uuid"
                class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start"
                :data-testid="`action-${item.kind}`"
            >
                <span
                    :class="[
                        'flex size-8 shrink-0 items-center justify-center rounded-lg max-sm:hidden',
                        ICON_CLASS[item.kind],
                    ]"
                >
                    <component :is="ICONS[item.kind]" class="size-4" />
                </span>

                <div class="min-w-0 flex-1 space-y-1">
                    <p class="truncate text-sm font-semibold text-foreground">
                        <bdi>{{ label(item) }}</bdi>
                    </p>

                    <template v-if="item.kind === 'rejected'">
                        <p
                            v-if="item.rejection?.at"
                            class="text-xs text-destructive"
                        >
                            {{ t('dashboard.actions.rejectedOn') }}
                            <bdi dir="ltr">{{
                                formatDate(item.rejection.at, locale)
                            }}</bdi>
                        </p>
                        <p
                            v-if="item.rejection?.reason"
                            class="line-clamp-3 rounded border-s-2 border-destructive/40 bg-muted/60 px-2 py-1 text-xs leading-relaxed text-foreground"
                            data-testid="action-reason"
                        >
                            <span class="sr-only"
                                >{{ t('dashboard.actions.reasonLabel') }} :
                            </span>
                            <bdi dir="auto">{{ item.rejection.reason }}</bdi>
                        </p>
                    </template>

                    <p
                        v-else-if="item.kind === 'ready'"
                        class="text-xs text-emerald-700 dark:text-emerald-400"
                    >
                        {{ t('dashboard.actions.ready') }}
                    </p>

                    <p
                        v-else-if="item.kind === 'replace'"
                        class="text-xs text-destructive"
                        data-testid="action-to-replace"
                    >
                        {{
                            t(
                                'dashboard.actions.toReplace',
                                { count: item.replacement?.to_replace ?? 0 },
                                item.replacement?.to_replace ?? 0,
                            )
                        }}
                    </p>

                    <p
                        v-else-if="item.kind === 'awaiting'"
                        class="text-xs text-muted-foreground"
                        data-testid="action-awaiting-note"
                    >
                        {{ t('dashboard.actions.awaiting') }}
                    </p>

                    <ul
                        v-else
                        class="space-y-0.5 text-xs text-muted-foreground"
                        data-testid="action-blockers"
                    >
                        <li v-for="(reason, i) in item.blockers" :key="i">
                            {{ reasonText(reason) }}
                        </li>
                    </ul>
                </div>

                <Button
                    as-child
                    size="sm"
                    :variant="item.kind === 'ready' ? 'default' : 'outline'"
                    class="shrink-0 self-start"
                >
                    <Link :href="show(item.uuid)">
                        {{ t(ACTION_KEY[item.kind]) }}
                    </Link>
                </Button>
            </li>
        </ul>
    </section>
</template>
