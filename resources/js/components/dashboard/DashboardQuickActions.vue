<script setup lang="ts">
import { BookOpen, Clapperboard, CodeXml, Music, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const emit = defineEmits<{
    (e: 'select-category', category: string): void;
}>();

const categories = computed(() => [
    {
        id: 'music',
        title: t('dashboard.quickActions.music'),
        description: t('dashboard.quickActions.musicDesc'),
        icon: Music,
        gradient:
            'from-blue-500/10 to-indigo-500/10 hover:from-blue-500/20 hover:to-indigo-500/20 dark:from-blue-950/40 dark:to-indigo-950/40',
        borderColor: 'border-blue-500/20 hover:border-blue-500/50',
        iconBg: 'bg-blue-600/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400',
        count: '8',
    },
    {
        id: 'literature',
        title: t('dashboard.quickActions.literature'),
        description: t('dashboard.quickActions.literatureDesc'),
        icon: BookOpen,
        gradient:
            'from-amber-500/10 to-orange-500/10 hover:from-amber-500/20 hover:to-orange-500/20 dark:from-amber-950/40 dark:to-orange-950/40',
        borderColor: 'border-amber-500/20 hover:border-amber-500/50',
        iconBg: 'bg-amber-600/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400',
        count: '3',
    },
    {
        id: 'cinema',
        title: t('dashboard.quickActions.cinema'),
        description: t('dashboard.quickActions.cinemaDesc'),
        icon: Clapperboard,
        gradient:
            'from-purple-500/10 to-pink-500/10 hover:from-purple-500/20 hover:to-pink-500/20 dark:from-purple-950/40 dark:to-pink-950/40',
        borderColor: 'border-purple-500/20 hover:border-purple-500/50',
        iconBg: 'bg-purple-600/10 text-purple-600 dark:bg-purple-500/20 dark:text-purple-400',
        count: '5',
    },
    {
        id: 'software',
        title: t('dashboard.quickActions.software'),
        description: t('dashboard.quickActions.softwareDesc'),
        icon: CodeXml,
        gradient:
            'from-onda-teal-500/10 to-emerald-500/10 hover:from-onda-teal-500/20 hover:to-emerald-500/20 dark:from-onda-teal-950/40 dark:to-emerald-950/40',
        borderColor: 'border-onda-teal-500/20 hover:border-onda-teal-500/50',
        iconBg: 'bg-onda-teal-600/10 text-onda-teal-600 dark:bg-onda-teal-500/20 dark:text-onda-teal-400',
        count: '2',
    },
]);
</script>

<template>
    <div class="space-y-3.5">
        <div class="flex items-center justify-between">
            <div>
                <h2
                    class="text-base font-bold tracking-tight text-foreground sm:text-lg"
                >
                    {{ t('dashboard.quickActions.title') }}
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{ t('dashboard.quickActions.subtitle') }}
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <button
                v-for="cat in categories"
                :key="cat.id"
                type="button"
                :class="[
                    'group relative flex cursor-pointer flex-col justify-between rounded-2xl border bg-card p-4.5 text-start transition-all duration-300 hover:-translate-y-1 hover:shadow-md',
                    cat.borderColor,
                    cat.gradient,
                ]"
                @click="emit('select-category', cat.id)"
            >
                <div class="flex w-full items-start justify-between gap-2">
                    <div
                        :class="[
                            'flex size-11 items-center justify-center rounded-xl shadow-xs transition-transform duration-300 group-hover:scale-110',
                            cat.iconBg,
                        ]"
                    >
                        <component :is="cat.icon" class="size-5" />
                    </div>

                    <span
                        class="inline-flex size-7 items-center justify-center rounded-full bg-background/80 text-muted-foreground shadow-xs transition-colors group-hover:bg-foreground group-hover:text-background"
                    >
                        <Plus class="size-4" />
                    </span>
                </div>

                <div class="mt-4 space-y-1">
                    <h3
                        class="text-sm font-bold text-foreground transition-colors group-hover:text-onda-blue-600 dark:group-hover:text-onda-blue-400"
                    >
                        {{ cat.title }}
                    </h3>
                    <p
                        class="line-clamp-2 text-xs leading-relaxed text-muted-foreground"
                    >
                        {{ cat.description }}
                    </p>
                </div>
            </button>
        </div>
    </div>
</template>
