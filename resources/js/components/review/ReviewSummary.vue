<script setup lang="ts">
import { CircleX, Clock, Lock } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useCountUp } from '@/composables/useCountUp';

/**
 * Where a deposit under review stands, for its author, at a glance: how
 * many files the ONDA accepted, how many wait for it, how many the author
 * must fix — three counters and one segmented bar. Each segment has an
 * icon and words next to it; colour is never the only signal.
 */
const props = defineProps<{
    accepted: number;
    waiting: number;
    toFix: number;
}>();

const { t } = useI18n();

const shownAccepted = useCountUp(() => props.accepted);
const shownWaiting = useCountUp(() => props.waiting);
const shownToFix = useCountUp(() => props.toFix);

const total = computed(() => props.accepted + props.waiting + props.toFix);

const share = (n: number): string =>
    total.value === 0 ? '0%' : `${(n / total.value) * 100}%`;

const items = computed(() => [
    {
        key: 'accepted',
        icon: Lock,
        value: shownAccepted.value,
        tone: 'text-emerald-700 dark:text-emerald-300',
    },
    {
        key: 'waiting',
        icon: Clock,
        value: shownWaiting.value,
        tone: 'text-muted-foreground',
    },
    {
        key: 'toFix',
        icon: CircleX,
        value: shownToFix.value,
        tone: 'text-destructive',
    },
]);
</script>

<template>
    <section
        class="space-y-3 rounded-xl border border-border/80 bg-card p-4"
        :aria-label="t('oeuvres.review.summaryTitle')"
        data-testid="review-summary"
    >
        <dl class="grid grid-cols-3 gap-2">
            <div
                v-for="item in items"
                :key="item.key"
                class="min-w-0"
                :data-testid="`summary-${item.key}`"
            >
                <dt
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <component
                        :is="item.icon"
                        class="size-3.5 shrink-0"
                        :class="item.tone"
                        aria-hidden="true"
                    />
                    <span class="truncate">{{
                        t(`oeuvres.review.${item.key}`)
                    }}</span>
                </dt>
                <dd
                    class="mt-0.5 text-xl font-semibold tabular-nums"
                    :class="item.tone"
                >
                    <bdi dir="ltr">{{ item.value }}</bdi>
                </dd>
            </div>
        </dl>
        <div
            class="flex h-1.5 w-full overflow-hidden rounded-full bg-muted"
            role="img"
            :aria-label="
                t('oeuvres.review.barLabel', { accepted, waiting, toFix })
            "
            data-testid="summary-bar"
        >
            <span
                class="h-full bg-emerald-500 transition-[width] duration-500 motion-reduce:transition-none"
                :style="{ width: share(accepted) }"
                data-segment="accepted"
            />
            <span
                class="h-full bg-muted-foreground/40 transition-[width] duration-500 motion-reduce:transition-none"
                :style="{ width: share(waiting) }"
                data-segment="waiting"
            />
            <span
                class="h-full bg-destructive transition-[width] duration-500 motion-reduce:transition-none"
                :style="{ width: share(toFix) }"
                data-segment="toFix"
            />
        </div>
    </section>
</template>
