<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';

/**
 * Renders either an `Oeuvre` status (`oeuvres.status.*`, already established by
 * the author-facing pages) or a `MediaFile` status (`media.status.*`) as a
 * Badge — reusing the existing translation namespaces rather than
 * inventing admin-only copy for the same underlying enum.
 */
const props = defineProps<{
    kind: 'oeuvre' | 'media';
    status: string;
}>();

const { t } = useI18n();

const WORK_VARIANTS: Record<string, BadgeVariants['variant']> = {
    draft: 'outline',
    submitted: 'info',
    under_review: 'warning',
    registered: 'success',
    approved: 'success',
    distributed: 'info',
    rejected: 'destructive',
};

const MEDIA_VARIANTS: Record<string, BadgeVariants['variant']> = {
    uploading: 'outline',
    assembling: 'outline',
    scanning: 'warning',
    processing: 'info',
    ready: 'success',
    failed: 'destructive',
    quarantined: 'destructive',
};

const variant = computed<BadgeVariants['variant']>(
    () =>
        (props.kind === 'oeuvre' ? WORK_VARIANTS : MEDIA_VARIANTS)[
            props.status
        ] ?? 'outline',
);

const label = computed(() =>
    t(
        `${props.kind === 'oeuvre' ? 'oeuvres.status' : 'media.status'}.${props.status}`,
    ),
);
</script>

<template>
    <Badge :variant="variant">{{ label }}</Badge>
</template>
