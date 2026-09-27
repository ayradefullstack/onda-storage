<script setup lang="ts">
import Pagination from '@/components/ui/pagination/Pagination.vue';
import type { PageLink } from '@/components/ui/pagination/Pagination.vue';

defineProps<{
    links: PageLink[];
    from: number | null;
    to: number | null;
    total: number;
}>();
</script>

<template>
    <Pagination :links="links" :from="from" :to="to" :total="total" class="px-0 pt-3" />
</template>
