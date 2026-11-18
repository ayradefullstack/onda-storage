<script setup lang="ts">
import { Ban, Clock, PencilLine, SearchCheck, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import type { ReviewEntry } from './ReviewTimeline.vue';

/**
 * What the author needs to know about their deposit right now, above
 * everything else on the page: where it stands and, if it came back, why
 * and what to do next.
 *
 * A rejection shows the officer's reason word for word — it is the only
 * thing telling the author what to fix — and one primary action that takes
 * them to their files. Nothing here names an officer: the author is
 * answered by ONDA.
 */
const props = defineProps<{
    status: string;
    registeredAt: string | null;
    reviews: ReviewEntry[];
    /** The deposit is the author's to change again (rejected). */
    editable: boolean;
}>();

defineEmits<{ resubmit: [] }>();

const { t, locale } = useI18n();

/** The rejection that put it in its current state: the newest one. */
const rejection = computed(
    () =>
        [...props.reviews]
            .reverse()
            .find((review) => review.to_status === 'rejected') ?? null,
);

const TONES: Record<string, string> = {
    submitted: 'border-sky-500/30 bg-sky-500/5',
    under_review: 'border-amber-500/30 bg-amber-500/5',
    registered: 'border-emerald-500/30 bg-emerald-500/5',
    rejected: 'border-destructive/30 bg-destructive/5',
};

const ICON_TONES: Record<string, string> = {
    submitted: 'text-sky-600 dark:text-sky-400',
    under_review: 'text-amber-600 dark:text-amber-400',
    registered: 'text-emerald-600 dark:text-emerald-400',
    rejected: 'text-destructive',
};

const ICONS = {
    submitted: Clock,
    under_review: SearchCheck,
    registered: ShieldCheck,
    rejected: Ban,
} as const;

const known = computed(() => props.status in TONES);
const icon = computed(() => ICONS[props.status as keyof typeof ICONS]);
</script>

<template>
    <section
        v-if="known"
        class="motion-fade-up rounded-xl border p-4 sm:p-5"
        :class="TONES[status]"
        :role="status === 'rejected' ? 'alert' : 'status'"
        data-testid="deposit-status-banner"
        :data-status="status"
    >
        <div class="flex items-start gap-3">
            <component
                :is="icon"
                class="mt-0.5 size-5 shrink-0"
                :class="ICON_TONES[status]"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1 space-y-1">
                <h2 class="text-sm font-semibold text-foreground">
                    {{ t(`review.banner.${status}.title`) }}
                </h2>

                <p
                    v-if="status === 'registered'"
                    class="text-sm text-muted-foreground"
                >
                    <i18n-t
                        v-if="registeredAt"
                        keypath="review.banner.registered.body"
                        tag="span"
                    >
                        <template #date>
                            <bdi
                                dir="ltr"
                                class="font-medium text-foreground"
                                >{{ formatDate(registeredAt, locale) }}</bdi
                            >
                        </template>
                    </i18n-t>
                    <span v-else>{{
                        t('review.banner.registered.bodyNoDate')
                    }}</span>
                </p>

                <template v-else-if="status === 'rejected'">
                    <p
                        v-if="rejection?.created_at"
                        class="text-xs text-muted-foreground"
                        data-testid="rejection-date"
                    >
                        <i18n-t
                            keypath="review.banner.rejected.decidedOn"
                            tag="span"
                        >
                            <template #date>
                                <bdi dir="ltr">{{
                                    formatDate(rejection.created_at, locale)
                                }}</bdi>
                            </template>
                        </i18n-t>
                    </p>

                    <div
                        v-if="rejection?.reason"
                        class="mt-2 rounded-lg border border-destructive/20 bg-background p-3"
                        data-testid="rejection-reason"
                    >
                        <p
                            class="text-[11px] font-semibold tracking-wide text-destructive uppercase"
                        >
                            {{ t('review.banner.rejected.reasonTitle') }}
                        </p>
                        <p
                            class="mt-1 text-sm leading-relaxed whitespace-pre-line text-foreground"
                        >
                            <bdi dir="auto">{{ rejection.reason }}</bdi>
                        </p>
                    </div>

                    <div
                        v-if="editable"
                        class="flex flex-col gap-2 pt-2 sm:flex-row sm:items-center"
                    >
                        <Button
                            data-testid="resubmit-action"
                            @click="$emit('resubmit')"
                        >
                            <PencilLine class="size-4" />
                            {{ t('review.banner.rejected.action') }}
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            {{ t('review.banner.rejected.hint') }}
                        </p>
                    </div>
                </template>

                <p v-else class="text-sm text-muted-foreground">
                    {{ t(`review.banner.${status}.body`) }}
                </p>
            </div>
        </div>
    </section>
</template>
