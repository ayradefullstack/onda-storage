<script setup lang="ts">
import DataTablePagination from '@/components/ui/data-table/DataTablePagination.vue';
import type { PageLink } from '@/components/ui/data-table/DataTablePagination.vue';

/**
 * Pager for the admin tables. Shows the rows-per-page selector when the page
 * passes `perPage`; the server (ResolvesPerPage) only honours its allow-list.
 */
withDefaults(
    defineProps<{
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
        perPage?: number;
    }>(),
    { perPage: undefined },
);
</script>

<template>
    <DataTablePagination
        :links="links"
        :from="from"
        :to="to"
        :total="total"
        :per-page="perPage"
        :show-page-size="perPage !== undefined"
        class="rounded-lg border-t-0 px-0 pt-3"
    />
</template>
