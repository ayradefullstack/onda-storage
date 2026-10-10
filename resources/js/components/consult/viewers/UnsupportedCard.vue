<script setup lang="ts">
import { FileQuestionMark, Loader2Icon, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatBytes, formatDate } from '@/lib/format';
import type { ConsultationDescriptor } from '@/types/consultation';

/**
 * Pending, failed, unsupported — always a metadata card with the exact
 * reason, never a "download instead".
 */
const props = defineProps<{
    descriptor: ConsultationDescriptor;
    compact?: boolean;
    gaveUp?: boolean;
}>();

const { t, te, locale } = useI18n();

const reasonText = computed(() => {
    const reason = props.descriptor.reason;

    if (reason !== null && te(`consult.reason.${reason}`)) {
        return t(`consult.reason.${reason}`);
    }

    if (props.descriptor.status === 'failed') {
        return t('consult.reason.render_failed');
    }

    return t('consult.reason.unsupported_format');
});
</script>

<template>
    <div
        class="flex h-full min-h-48 flex-col items-center justify-center gap-3 p-6 text-center"
        :data-state="descriptor.status"
    >
        <template v-if="descriptor.status === 'pending'">
            <Loader2Icon
                v-if="!gaveUp"
                class="size-8 animate-spin text-muted-foreground"
            />
            <p class="text-sm font-medium">
                {{
                    gaveUp
                        ? t('consult.stillPreparing')
                        : descriptor.reason === 'not_generated'
                          ? t('consult.reason.not_generated')
                          : t('consult.preparing')
                }}
            </p>
        </template>
        <template v-else>
            <component
                :is="
                    descriptor.status === 'failed'
                        ? TriangleAlert
                        : FileQuestionMark
                "
                class="size-8 text-muted-foreground"
            />
            <p class="text-sm font-medium">
                {{
                    descriptor.status === 'failed'
                        ? t('consult.failedTitle')
                        : t('consult.unsupportedTitle')
                }}
            </p>
            <p class="max-w-sm text-sm text-muted-foreground">
                {{ reasonText }}
            </p>
        </template>

        <dl
            v-if="!compact"
            class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-start text-xs text-muted-foreground"
        >
            <dt>{{ t('consult.meta.name') }}</dt>
            <dd>
                <bdi>{{ descriptor.meta.filename }}</bdi>
            </dd>
            <dt>{{ t('consult.meta.size') }}</dt>
            <dd>
                <bdi dir="ltr">{{
                    formatBytes(descriptor.meta.sizeBytes, locale)
                }}</bdi>
            </dd>
            <template v-if="descriptor.meta.depositedAt">
                <dt>{{ t('consult.meta.deposited') }}</dt>
                <dd>
                    <bdi dir="ltr">{{
                        formatDate(descriptor.meta.depositedAt, locale)
                    }}</bdi>
                </dd>
            </template>
        </dl>
    </div>
</template>
