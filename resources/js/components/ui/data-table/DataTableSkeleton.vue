<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

interface Props {
    rows?: number;
    cols?: number;
}

withDefaults(defineProps<Props>(), {
    rows: 5,
    cols: 5,
});
</script>

<template>
    <tbody class="divide-y divide-border/60">
        <tr v-for="r in rows" :key="r" class="h-12">
            <td v-for="c in cols" :key="c" class="px-4 py-3.5">
                <Skeleton
                    :class="[
                        'h-4 rounded',
                        c === 1 ? 'w-8' : c === 2 ? 'w-36' : c === cols ? 'w-16 ms-auto' : 'w-24',
                    ]"
                />
            </td>
        </tr>
    </tbody>
</template>
