<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Clock, FileText, ShieldAlert, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { DashboardCounts } from '@/components/dashboard/types';
import { CountUp } from '@/components/ui/count-up';
import { index } from '@/routes/oeuvres';

const props = defineProps<{ counts: DashboardCounts }>();

const { t } = useI18n();

/**
 * Each card links to the works table filtered on exactly the set it counts:
 * `pending` is the same submitted + under-review filter the controller
 * applies, so the number and the list behind it cannot disagree.
 */
const cards = computed(() => [
    {
        key: 'draft',
        label: t('dashboard.counts.draft'),
        hint: null,
        icon: FileText,
        iconClass: 'bg-muted text-muted-foreground',
    },
    {
        key: 'pending',
        label: t('dashboard.counts.pending'),
        hint: t('dashboard.counts.pendingHint'),
        icon: Clock,
        iconClass:
            'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400',
    },
    {
        key: 'registered',
        label: t('dashboard.counts.registered'),
        hint: null,
        icon: ShieldCheck,
        iconClass:
            'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400',
    },
    {
        key: 'rejected',
        label: t('dashboard.counts.rejected'),
        hint: null,
        icon: ShieldAlert,
        iconClass: 'bg-destructive/10 text-destructive',
    },
]);
</script>

<template>
    <section :aria-label="t('dashboard.counts.title')">
        <ul class="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
            <li
                v-for="(card, i) in cards"
                :key="card.key"
                class="motion-fade-up"
                :style="{ '--motion-index': i }"
            >
                <Link
                    :href="index({ query: { status: card.key } })"
                    class="motion-lift flex h-full flex-col gap-3 rounded-xl border border-border bg-card p-4 shadow-xs transition-colors outline-none hover:border-border/80 hover:bg-muted/30 focus-visible:ring-2 focus-visible:ring-ring sm:p-5"
                    :aria-label="
                        t('dashboard.counts.open', {
                            label: card.label,
                            count: props.counts[card.key] ?? 0,
                        })
                    "
                    :data-testid="`count-${card.key}`"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span
                            class="text-xs font-semibold text-muted-foreground"
                        >
                            {{ card.label }}
                        </span>
                        <span
                            :class="[
                                'flex size-8 shrink-0 items-center justify-center rounded-lg',
                                card.iconClass,
                            ]"
                        >
                            <component :is="card.icon" class="size-4" />
                        </span>
                    </div>
                    <p
                        class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                        data-testid="count-value"
                    >
                        <CountUp :value="props.counts[card.key] ?? 0" />
                    </p>
                    <p
                        v-if="card.hint"
                        class="text-[11px] leading-relaxed text-muted-foreground"
                    >
                        {{ card.hint }}
                    </p>
                </Link>
            </li>
        </ul>
    </section>
</template>
