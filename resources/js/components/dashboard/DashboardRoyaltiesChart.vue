<script setup lang="ts">
import {
    Building2,
    Calendar,
    Globe2,
    PieChart,
    Radio,
    Tv,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface Channel {
    id: string;
    title: string;
    amount: string;
    percentage: number;
    icon: typeof Tv;
    color: string;
    barColor: string;
}

const channels = computed<Channel[]>(() => [
    {
        id: 'tv',
        title: t('dashboard.royalties.tv'),
        amount: '202,650 DZD',
        percentage: 42,
        icon: Tv,
        color: 'text-blue-600 dark:text-blue-400 bg-blue-500/10 dark:bg-blue-500/20',
        barColor: 'bg-blue-600 dark:bg-blue-500',
    },
    {
        id: 'radio',
        title: t('dashboard.royalties.radio'),
        amount: '135,100 DZD',
        percentage: 28,
        icon: Radio,
        color: 'text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 dark:bg-emerald-500/20',
        barColor: 'bg-emerald-600 dark:bg-emerald-500',
    },
    {
        id: 'streaming',
        title: t('dashboard.royalties.streaming'),
        amount: '86,850 DZD',
        percentage: 18,
        icon: Globe2,
        color: 'text-purple-600 dark:text-purple-400 bg-purple-500/10 dark:bg-purple-500/20',
        barColor: 'bg-purple-600 dark:bg-purple-500',
    },
    {
        id: 'venues',
        title: t('dashboard.royalties.venues'),
        amount: '57,900 DZD',
        percentage: 12,
        icon: Building2,
        color: 'text-amber-600 dark:text-amber-400 bg-amber-500/10 dark:bg-amber-500/20',
        barColor: 'bg-amber-600 dark:bg-amber-500',
    },
]);

const payouts = [
    {
        id: '1',
        period: 'Session T2 - 2026 (Audiovisuel & TV)',
        amount: '184,500 DZD',
        date: '20 Août 2026',
        status: 'paid',
    },
    {
        id: '2',
        period: 'Session T1 - 2026 (Radiodiffusion)',
        amount: '142,000 DZD',
        date: '15 Mai 2026',
        status: 'paid',
    },
    {
        id: '3',
        period: 'Session Annuelle 2025 (Droits Numériques)',
        amount: '156,000 DZD',
        date: '10 Jan 2026',
        status: 'paid',
    },
];
</script>

<template>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Left 2 Cols: Revenue Channel Breakdown -->
        <div
            class="space-y-5 rounded-2xl border border-border/80 bg-card p-5 shadow-xs sm:p-6 lg:col-span-2"
        >
            <div
                class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"
            >
                <div>
                    <h3
                        class="flex items-center gap-2 text-base font-bold tracking-tight text-foreground sm:text-lg"
                    >
                        <PieChart
                            class="size-5 text-onda-blue-600 dark:text-onda-blue-400"
                        />
                        {{ t('dashboard.royalties.title') }}
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        {{ t('dashboard.royalties.subtitle') }}
                    </p>
                </div>

                <div class="text-start sm:text-end">
                    <span
                        class="text-[11px] font-semibold text-muted-foreground uppercase"
                    >
                        {{ t('dashboard.royalties.totalEarned') }}
                    </span>
                    <p
                        class="font-mono text-xl font-extrabold text-foreground sm:text-2xl"
                    >
                        482,500
                        <span class="font-sans text-xs text-muted-foreground"
                            >DZD</span
                        >
                    </p>
                </div>
            </div>

            <!-- Visual Multi-segment bar -->
            <div
                class="flex h-3.5 w-full gap-0.5 overflow-hidden rounded-full bg-muted/60 p-0.5"
            >
                <div
                    v-for="ch in channels"
                    :key="ch.id"
                    :class="[
                        'h-full rounded-full transition-all duration-700',
                        ch.barColor,
                    ]"
                    :style="{ width: `${ch.percentage}%` }"
                    :title="`${ch.title}: ${ch.percentage}%`"
                />
            </div>

            <!-- Channel Details Grid -->
            <div class="grid grid-cols-1 gap-3.5 pt-1 sm:grid-cols-2">
                <div
                    v-for="ch in channels"
                    :key="ch.id"
                    class="flex items-center justify-between rounded-xl border border-border/60 bg-muted/20 p-3.5 transition-colors hover:bg-muted/40"
                >
                    <div class="flex items-center gap-3">
                        <div
                            :class="[
                                'flex size-9 items-center justify-center rounded-lg',
                                ch.color,
                            ]"
                        >
                            <component :is="ch.icon" class="size-4.5" />
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-foreground">
                                {{ ch.title }}
                            </p>
                            <span
                                class="font-mono text-[11px] text-muted-foreground"
                            >
                                {{ ch.percentage }}% du total
                            </span>
                        </div>
                    </div>

                    <div class="text-end">
                        <p class="font-mono text-xs font-bold text-foreground">
                            {{ ch.amount }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right 1 Col: Payout History Timeline -->
        <div
            class="space-y-4 rounded-2xl border border-border/80 bg-card p-5 shadow-xs sm:p-6"
        >
            <div class="flex items-center justify-between">
                <h3
                    class="flex items-center gap-2 text-sm font-bold tracking-tight text-foreground"
                >
                    <Wallet
                        class="size-4 text-emerald-600 dark:text-emerald-400"
                    />
                    {{ t('dashboard.royalties.recentTimeline') }}
                </h3>
            </div>

            <div class="space-y-3">
                <div
                    v-for="payout in payouts"
                    :key="payout.id"
                    class="relative space-y-1.5 rounded-xl border border-border/60 bg-muted/15 p-3 transition-colors hover:bg-muted/30"
                >
                    <div class="flex items-center justify-between gap-1">
                        <p
                            class="max-w-[170px] truncate text-xs font-semibold text-foreground"
                        >
                            {{ payout.period }}
                        </p>
                        <span
                            class="font-mono text-xs font-bold text-emerald-600 dark:text-emerald-400"
                        >
                            {{ payout.amount }}
                        </span>
                    </div>

                    <div
                        class="flex items-center justify-between text-[11px] text-muted-foreground"
                    >
                        <span class="flex items-center gap-1">
                            <Calendar class="size-3" />
                            {{ payout.date }}
                        </span>
                        <span
                            class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400"
                        >
                            <span class="size-1 rounded-full bg-emerald-500" />
                            {{ t('dashboard.royalties.payoutPaid') }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
