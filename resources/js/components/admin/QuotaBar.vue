<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatBytes } from '@/lib/format';

const props = defineProps<{
    usedBytes: number;
    limitBytes: number;
}>();

const { locale } = useI18n();

const percent = computed(() => {
    if (props.limitBytes <= 0) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((props.usedBytes / props.limitBytes) * 100),
    );
});

const barColor = computed(() => {
    if (percent.value >= 90) {
        return 'bg-destructive';
    }

    if (percent.value >= 75) {
        return 'bg-chart-4';
    }

    return 'bg-primary';
});
</script>

<template>
    <div class="w-full">
        <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
            <div
                class="h-full rounded-full transition-[width]"
                :class="barColor"
                :style="{ width: `${percent}%` }"
            />
        </div>
        <i18n-t
            keypath="admin.quota.usedOfLimit"
            tag="p"
            class="mt-1 text-xs text-muted-foreground"
        >
            <template #used
                ><bdi dir="ltr">{{
                    formatBytes(usedBytes, locale)
                }}</bdi></template
            >
            <template #limit
                ><bdi dir="ltr">{{
                    formatBytes(limitBytes, locale)
                }}</bdi></template
            >
            <template #percent
                ><bdi dir="ltr">{{ percent }}</bdi></template
            >
        </i18n-t>
    </div>
</template>
