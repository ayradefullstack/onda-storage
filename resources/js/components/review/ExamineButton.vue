<script setup lang="ts">
import { ClipboardCheck } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';

/**
 * "Examiner": the one way into a deposit's decisions, in a table row and in
 * the oeuvre page's header alike. A plain button — no menu in between —
 * that opens ReviewDecisionDialog.
 */
defineProps<{
    /** The oeuvre's label, for the accessible name in a list of rows. */
    label?: string;
    size?: 'sm' | 'default';
}>();

const emit = defineEmits<{ click: [] }>();

const { t } = useI18n();
</script>

<template>
    <Button
        type="button"
        :size="size ?? 'sm'"
        data-testid="examine"
        :aria-label="
            label ? t('review.examineAria', { label }) : t('review.examine')
        "
        aria-haspopup="dialog"
        @click="emit('click')"
    >
        <ClipboardCheck class="size-4" />
        {{ t('review.examine') }}
    </Button>
</template>
