<script setup lang="ts">
import { HardDrive, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { DashboardQuota } from '@/components/dashboard/types';
import { formatBytes } from '@/lib/format';

const props = defineProps<{ quota: DashboardQuota }>();

const { t, locale } = useI18n();

/** What InitUpload counts against the limit: stored plus reserved bytes. */
const taken = computed(
    () => props.quota.used_bytes + props.quota.reserved_bytes,
);

const percent = computed(() =>
    props.quota.limit_bytes > 0
        ? Math.min(
              100,
              Math.round((taken.value / props.quota.limit_bytes) * 100),
          )
        : 100,
);

const warning = computed(() => percent.value > 80);
</script>

<template>
    <section
        class="space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs sm:p-5"
        aria-labelledby="dashboard-storage-title"
        data-testid="dashboard-storage"
        :data-warning="warning"
    >
        <h2
            id="dashboard-storage-title"
            class="flex items-center gap-2 text-sm font-bold tracking-tight text-foreground sm:text-base"
        >
            <HardDrive class="size-4 text-muted-foreground" />
            {{ t('dashboard.storage.title') }}
        </h2>

        <i18n-t
            keypath="dashboard.storage.usage"
            tag="p"
            class="text-sm font-semibold text-foreground"
            data-testid="storage-usage"
        >
            <template #used>
                <bdi dir="ltr" class="tabular-nums">{{
                    formatBytes(taken, locale)
                }}</bdi>
            </template>
            <template #limit>
                <bdi dir="ltr" class="tabular-nums">{{
                    formatBytes(quota.limit_bytes, locale)
                }}</bdi>
            </template>
        </i18n-t>

        <div
            class="h-2 w-full overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-valuenow="percent"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="t('dashboard.storage.title')"
        >
            <div
                :class="[
                    'h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none',
                    warning ? 'bg-amber-500' : 'bg-primary',
                ]"
                :style="{ width: `${percent}%` }"
            />
        </div>

        <p
            v-if="quota.reserved_bytes > 0"
            class="text-[11px] text-muted-foreground"
            data-testid="storage-reserved"
        >
            {{
                t('dashboard.storage.reserved', {
                    size: formatBytes(quota.reserved_bytes, locale),
                })
            }}
        </p>

        <p
            v-if="warning"
            class="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400"
            data-testid="storage-warning"
        >
            <TriangleAlert class="mt-0.5 size-3.5 shrink-0" />
            {{ t('dashboard.storage.warning') }}
        </p>

        <p class="text-[11px] leading-relaxed text-muted-foreground">
            {{ t('dashboard.storage.removedNote') }}
        </p>
    </section>
</template>
