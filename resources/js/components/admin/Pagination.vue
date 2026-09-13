<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}

defineProps<{
    links: PageLink[];
    from: number | null;
    to: number | null;
    total: number;
}>();
</script>

<template>
    <nav
        v-if="links.length > 3"
        class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3"
    >
        <p class="text-xs text-muted-foreground">
            <i18n-t keypath="admin.pagination.showing" tag="span">
                <template #from
                    ><bdi dir="ltr">{{ from ?? 0 }}</bdi></template
                >
                <template #to
                    ><bdi dir="ltr">{{ to ?? 0 }}</bdi></template
                >
                <template #total
                    ><bdi dir="ltr">{{ total }}</bdi></template
                >
            </i18n-t>
        </p>
        <div class="flex flex-wrap items-center gap-1">
            <template v-for="(link, index) in links" :key="index">
                <span
                    v-if="link.url === null"
                    class="rounded-md px-2.5 py-1.5 text-xs text-muted-foreground/50"
                    v-html="link.label"
                />
                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    class="rounded-md px-2.5 py-1.5 text-xs"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                    "
                >
                    <span v-html="link.label" />
                </Link>
            </template>
        </div>
    </nav>
</template>
