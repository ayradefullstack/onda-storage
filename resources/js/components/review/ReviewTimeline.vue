<script setup lang="ts">
import { ArrowRight } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { REVEAL_MAX_ITEMS, vReveal } from '@/composables/useReveal';
import { formatDate } from '@/lib/format';
import { exactDateTime, relativeTime } from './relativeTime';

export interface ReviewEntry {
    uuid: string;
    from_status: string;
    to_status: string;
    reason: string | null;
    created_at: string | null;
    /** Admin console only. Absent from an author's page props altogether. */
    actor?: string | null;
}

/**
 * The deposit's decision history, newest first: one entry per status
 * change, straight from the append-only `oeuvre_reviews` rows.
 *
 * `anonymous` is the author's view: every entry is signed "ONDA". It is
 * not a rendering choice over hidden data — the author's page is never
 * sent an officer's name — it only decides what stands in the name's place
 * (the author's own moves, submitting and resubmitting, are theirs and are
 * left unsigned).
 */
const props = withDefaults(
    defineProps<{
        reviews: ReviewEntry[];
        anonymous?: boolean;
    }>(),
    { anonymous: false },
);

const { t, locale } = useI18n();

const entries = computed(() => [...props.reviews].reverse());

// "il y a 3 minutes" depends on the clock: the server render and the
// browser would disagree a minute later. The plain date is what both
// render; the relative form takes over once mounted.
const mounted = ref(false);

onMounted(() => {
    mounted.value = true;
});

const when = (isoString: string): string =>
    mounted.value
        ? relativeTime(isoString, locale.value)
        : formatDate(isoString, locale.value);
const reveal = computed(() => props.reviews.length <= REVEAL_MAX_ITEMS);

/** Submitting is the author's act; everything else is the office's. */
const isOfficeMove = (entry: ReviewEntry): boolean =>
    entry.to_status !== 'submitted';

function signature(entry: ReviewEntry): string | null {
    if (props.anonymous) {
        return isOfficeMove(entry) ? t('review.timeline.onda') : null;
    }

    return entry.actor ?? null;
}
</script>

<template>
    <section class="space-y-3" data-testid="review-timeline">
        <h2 class="text-sm font-medium">{{ t('review.timeline.title') }}</h2>

        <p v-if="entries.length === 0" class="text-sm text-muted-foreground">
            {{ t('review.timeline.empty') }}
        </p>

        <ol v-else class="relative space-y-4 border-s border-border ps-5">
            <li
                v-for="entry in entries"
                :key="entry.uuid"
                v-reveal="reveal"
                class="relative"
                data-testid="timeline-entry"
                :data-to="entry.to_status"
            >
                <span
                    class="absolute -start-[1.6rem] top-1.5 size-2.5 rounded-full border-2 border-background bg-muted-foreground/60"
                    aria-hidden="true"
                />
                <div class="flex flex-wrap items-center gap-1.5">
                    <StatusBadge kind="oeuvre" :status="entry.from_status" />
                    <ArrowRight
                        class="size-3.5 shrink-0 text-muted-foreground rtl:rotate-180"
                        aria-hidden="true"
                    />
                    <StatusBadge kind="oeuvre" :status="entry.to_status" />
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    <bdi
                        v-if="signature(entry)"
                        class="font-medium text-foreground"
                        data-testid="timeline-actor"
                        >{{ signature(entry) }}</bdi
                    >
                    <template v-if="signature(entry) && entry.created_at">
                        ·
                    </template>
                    <time
                        v-if="entry.created_at"
                        :datetime="entry.created_at"
                        :title="exactDateTime(entry.created_at, locale)"
                        class="cursor-help underline decoration-dotted underline-offset-2"
                    >
                        <bdi>{{ when(entry.created_at) }}</bdi>
                    </time>
                </p>
                <blockquote
                    v-if="entry.reason"
                    class="mt-2 rounded-md border-s-2 border-border bg-muted/50 px-3 py-2 text-sm leading-relaxed whitespace-pre-line"
                    data-testid="timeline-reason"
                >
                    <span class="sr-only"
                        >{{ t('review.timeline.reason') }} :
                    </span>
                    <bdi dir="auto">{{ entry.reason }}</bdi>
                </blockquote>
            </li>
        </ol>
    </section>
</template>
