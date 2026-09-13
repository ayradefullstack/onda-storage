<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';

/**
 * Renders either a `Work` status (`works.status.*`, already established by
 * the author-facing pages) or a `MediaFile` status (`media.status.*`) as a
 * Badge — reusing the existing translation namespaces rather than
 * inventing admin-only copy for the same underlying enum.
 */
const props = defineProps<{
    kind: 'work' | 'media';
    status: string;
}>();

const { t } = useI18n();

const WORK_VARIANTS: Record<string, BadgeVariants['variant']> = {
    draft: 'outline',
    submitted: 'secondary',
    under_review: 'secondary',
    registered: 'default',
    rejected: 'destructive',
};

const MEDIA_VARIANTS: Record<string, BadgeVariants['variant']> = {
    uploading: 'outline',
    assembling: 'outline',
    scanning: 'outline',
    processing: 'secondary',
    ready: 'default',
    failed: 'destructive',
    quarantined: 'destructive',
};

const variant = computed<BadgeVariants['variant']>(
    () =>
        (props.kind === 'work' ? WORK_VARIANTS : MEDIA_VARIANTS)[
            props.status
        ] ?? 'outline',
);

const label = computed(() =>
    t(
        `${props.kind === 'work' ? 'works.status' : 'media.status'}.${props.status}`,
    ),
);
</script>

<template>
    <Badge :variant="variant">{{ label }}</Badge>
</template>
