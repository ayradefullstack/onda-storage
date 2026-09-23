<script setup lang="ts">
import type { FileCheck2 } from '@lucide/vue';
import { ArrowUpRight, Award, Clock, Coins, Layers } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

interface StatItem {
    id: string;
    title: string;
    value: string;
    sublabel: string;
    trend?: string;
    trendPositive?: boolean;
    icon: typeof FileCheck2;
    iconColor: string;
    glowColor: string;
    progress?: number;
}

const stats = computed<StatItem[]>(() => [
    {
        id: 'works',
        title: t('dashboard.stats.worksTitle'),
        value: '18',
        sublabel: t('dashboard.stats.worksSubtitle'),
        trend: '+20%',
        trendPositive: true,
        icon: Layers,
        iconColor:
            'bg-onda-blue-500/10 text-onda-blue-600 dark:bg-onda-blue-500/20 dark:text-onda-blue-400',
        glowColor:
            'group-hover:border-onda-blue-500/40 group-hover:shadow-onda-blue-500/10',
        progress: 78,
    },
    {
        id: 'pending',
        title: t('dashboard.stats.pendingTitle'),
        value: '2',
        sublabel: t('dashboard.stats.pendingSubtitle'),
        trend: '48h avg',
        trendPositive: true,
        icon: Clock,
        iconColor:
            'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400',
        glowColor:
            'group-hover:border-amber-500/40 group-hover:shadow-amber-500/10',
        progress: 40,
    },
    {
        id: 'royalties',
        title: t('dashboard.stats.royaltiesTitle'),
        value: '482,500 DZD',
        sublabel: t('dashboard.stats.royaltiesSubtitle'),
        trend: '+14.2%',
        trendPositive: true,
        icon: Coins,
        iconColor:
            'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400',
        glowColor:
            'group-hover:border-emerald-500/40 group-hover:shadow-emerald-500/10',
        progress: 88,
    },
    {
        id: 'certificates',
        title: t('dashboard.stats.certificatesTitle'),
        value: '18',
        sublabel: t('dashboard.stats.certificatesSubtitle'),
        trend: '100% SHA-256',
        trendPositive: true,
        icon: Award,
        iconColor:
            'bg-onda-teal-500/10 text-onda-teal-600 dark:bg-onda-teal-500/20 dark:text-onda-teal-400',
        glowColor:
            'group-hover:border-onda-teal-500/40 group-hover:shadow-onda-teal-500/10',
        progress: 100,
    },
]);
</script>

<template>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div
            v-for="stat in stats"
            :key="stat.id"
            :class="[
                'group relative overflow-hidden rounded-2xl border border-border/80 bg-card p-5 shadow-xs transition-all duration-300 hover:-translate-y-1 hover:shadow-md dark:bg-card/95',
                stat.glowColor,
            ]"
        >
            <!-- Card Header: Title & Icon -->
            <div class="flex items-center justify-between gap-2">
                <span
                    class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    {{ stat.title }}
                </span>
                <div
                    :class="[
                        'flex size-10 items-center justify-center rounded-xl shadow-2xs transition-transform duration-300 group-hover:scale-110',
                        stat.iconColor,
                    ]"
                >
                    <component :is="stat.icon" class="size-5" />
                </div>
            </div>

            <!-- Value & Trend Row -->
            <div class="mt-4 space-y-1">
                <div class="flex items-baseline justify-between gap-2">
                    <h3
                        class="font-mono text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl"
                    >
                        {{ stat.value }}
                    </h3>
                    <span
                        v-if="stat.trend"
                        class="inline-flex items-center gap-0.5 rounded-full bg-emerald-500/10 px-2 py-0.5 font-mono text-[11px] font-semibold text-emerald-600 dark:bg-emerald-500/20 dark:text-emerald-400"
                    >
                        <ArrowUpRight class="size-3 rtl:rotate-90" />
                        {{ stat.trend }}
                    </span>
                </div>

                <p class="text-xs leading-relaxed text-muted-foreground">
                    {{ stat.sublabel }}
                </p>
            </div>

            <!-- Mini Progress Bar / Visual Accent -->
            <div
                class="mt-4 h-1.5 w-full overflow-hidden rounded-full bg-muted/60"
            >
                <div
                    class="h-full rounded-full transition-all duration-700 ease-out"
                    :class="[
                        stat.id === 'works' &&
                            'bg-onda-blue-600 dark:bg-onda-blue-500',
                        stat.id === 'pending' &&
                            'bg-amber-500 dark:bg-amber-400',
                        stat.id === 'royalties' &&
                            'bg-emerald-600 dark:bg-emerald-500',
                        stat.id === 'certificates' &&
                            'bg-onda-teal-600 dark:bg-onda-teal-400',
                    ]"
                    :style="{ width: `${stat.progress}%` }"
                />
            </div>
        </div>
    </div>
</template>
