<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { cn } from '@/lib/utils';

export interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    links: PageLink[];
    from: number | null;
    to: number | null;
    total: number;
    class?: string;
}

defineProps<Props>();

const { t } = useI18n();
</script>

<template>
    <nav
        v-if="links && links.length > 3"
        :class="
            cn(
                'flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-border/80 px-4 py-3 select-none',
                $props.class,
            )
        "
        aria-label="Pagination"
    >
        <p class="text-xs text-muted-foreground">
            <template v-if="total > 0">
                <i18n-t keypath="admin.pagination.showing" tag="span">
                    <template #from>
                        <span class="font-semibold text-foreground">{{ from ?? 0 }}</span>
                    </template>
                    <template #to>
                        <span class="font-semibold text-foreground">{{ to ?? 0 }}</span>
                    </template>
                    <template #total>
                        <span class="font-semibold text-foreground">{{ total }}</span>
                    </template>
                </i18n-t>
            </template>
            <template v-else>
                {{ total }} {{ t('common.results', 'résultats') }}
            </template>
        </p>

        <div class="flex flex-wrap items-center gap-1">
            <template v-for="(link, index) in links" :key="index">
                <span
                    v-if="link.url === null"
                    class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2.5 text-xs text-muted-foreground/40 pointer-events-none select-none"
                    v-html="link.label"
                />
                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2.5 text-xs font-medium transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground font-semibold shadow-xs'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                >
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </nav>
</template>
