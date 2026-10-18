<script setup lang="ts">
import { CircleCheck, CircleX, Clock } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import { usePulseOnChange } from '@/composables/usePulseOnChange';
import { REVIEW_STATUS } from './fileReview';

/**
 * The officer's decision on one file (`media_files.review_status`) — not
 * its pipeline status, which StatusBadge `kind="media"` shows. Tones and
 * icons mirror ReviewStatusEnum::color() / icon(). Pulses once when the
 * decision changes under it.
 */
const props = defineProps<{ status: string }>();

const { t } = useI18n();
const pulsing = usePulseOnChange(() => props.status);

const VARIANTS: Record<string, BadgeVariants['variant']> = {
    [REVIEW_STATUS.PENDING]: 'outline',
    [REVIEW_STATUS.ACCEPTED]: 'success',
    [REVIEW_STATUS.REJECTED]: 'destructive',
};

const icon = computed(() =>
    props.status === REVIEW_STATUS.ACCEPTED
        ? CircleCheck
        : props.status === REVIEW_STATUS.REJECTED
          ? CircleX
          : Clock,
);
</script>

<template>
    <Badge
        :variant="VARIANTS[status] ?? 'outline'"
        :class="pulsing ? 'motion-pulse-once' : ''"
        data-testid="review-status-badge"
        :data-status="status"
    >
        <component :is="icon" class="size-3" aria-hidden="true" />
        {{ t(`review.file.status.${status}`) }}
    </Badge>
</template>
