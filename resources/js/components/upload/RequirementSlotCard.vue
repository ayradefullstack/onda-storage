<script setup lang="ts">
import {
    CheckCircle2,
    CircleDashed,
    CircleX,
    Clock,
    FileCheck,
    Info,
    Loader2,
    Lock,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import SlotHistory from '@/components/review/SlotHistory.vue';
import type { HistoryEvent } from '@/components/review/SlotHistory.vue';
import { Card, CardContent } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import DepositCard from '@/components/upload/DepositCard.vue';
import type { DepositEntry } from '@/components/upload/depositJourney';
import { entryKey, slotStateOf } from '@/components/upload/depositJourney';
import Dropzone from '@/components/upload/Dropzone.vue';
import { replacementDropzoneId } from '@/components/upload/replacement';
import type { RequirementSlot } from '@/components/upload/requirement';
import {
    describeConditions,
    formatExtensions,
} from '@/components/upload/requirement';
import { formatBytes } from '@/lib/format';

const props = defineProps<{
    requirement: RequirementSlot;
    position: number;
    oeuvreId: number;
    entries: DepositEntry[];
    quota: { used_bytes: number; limit_bytes: number };
    editable?: boolean;
    /**
     * The ONDA's per-file decisions are shown: the deposit is under review,
     * or was returned to its author (rejected).
     */
    reviewMode?: boolean;
    /**
     * How many removed rejected files of this slot wait for their
     * replacement (the server's `replacement.open`). Above 0 the slot shows
     * a "Remplacement attendu" dropzone; under review, only then.
     */
    replacementOpen?: number;
    /** The file whose removal is in flight, if any. */
    busyUuid?: string | null;
    /** Under review: the slot's own state (server: review_summary.slots). */
    reviewState?: 'to_fix' | 'waiting' | 'accepted' | null;
    /** The last rejection reason in this slot, shown above its dropzone. */
    reminder?: string | null;
    /** "Historique de ce document" (server: history[slot]). */
    history?: HistoryEvent[];
}>();

const emit = defineEmits<{
    pause: [id: string];
    resume: [id: string];
    cancel: [id: string];
    remove: [uuid: string];
    replace: [uuid: string];
}>();

const replacementExpected = computed(() => (props.replacementOpen ?? 0) > 0);

/**
 * Under review (not editable) a slot takes a file only to replace a
 * removed rejected one. On a deposit returned to its author (editable) the
 * slot's own dropzone stays, and reads "Remplacement attendu" while such a
 * file waits for its successor.
 */
const takesReplacement = computed(
    () => !props.editable && replacementExpected.value,
);

const { t, locale } = useI18n();

const state = computed(() =>
    slotStateOf(props.entries, props.requirement.is_required),
);

const locked = computed(
    () =>
        !props.requirement.allows_multiple &&
        (state.value === 'deposited' || state.value === 'inProgress'),
);

const mayNotApply = computed(
    () => props.requirement.conditions !== null && state.value !== 'deposited',
);
</script>

<template>
    <Card
        class="overflow-hidden transition-all duration-300"
        :class="[
            state === 'deposited'
                ? 'border-emerald-500/30 bg-card/90 shadow-xs'
                : state === 'inProgress'
                  ? 'border-onda-blue-500/50 bg-card shadow-sm ring-2 ring-onda-blue-500/15'
                  : state === 'awaiting'
                    ? 'border-2 border-dashed border-amber-500/30 bg-card/60'
                    : 'border-border/80 bg-card/60',
        ]"
    >
        <CardContent class="space-y-4 p-5 sm:p-6">
            <!-- Header Section -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                    <!-- Position indicator -->
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold tabular-nums transition-colors"
                        :class="[
                            state === 'deposited'
                                ? 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400'
                                : state === 'inProgress'
                                  ? 'bg-onda-blue-600 text-white shadow-xs'
                                  : 'bg-muted text-foreground/80',
                        ]"
                        aria-hidden="true"
                    >
                        {{ position }}
                    </span>

                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3
                                class="text-sm font-semibold tracking-tight text-foreground"
                            >
                                <bdi>{{ requirement.title }}</bdi>
                            </h3>

                            <span
                                v-if="requirement.is_required"
                                class="py-0.2 rounded bg-rose-500/10 px-1.5 text-[10px] font-semibold text-rose-600 dark:text-rose-400"
                            >
                                {{ t('oeuvres.step2.required') }}
                            </span>
                            <span
                                v-else
                                class="py-0.2 rounded bg-muted px-1.5 text-[10px] font-medium text-muted-foreground"
                            >
                                {{ t('oeuvres.step2.optionalBadge') }}
                            </span>
                        </div>

                        <!-- Technical Specs Chips -->
                        <div
                            class="flex flex-wrap items-center gap-2 pt-0.5 text-xs text-muted-foreground"
                        >
                            <span
                                class="inline-flex items-center rounded-md border border-border/70 bg-muted/30 px-2 py-0.5 font-mono text-[11px] text-foreground"
                            >
                                <bdi dir="ltr">{{
                                    formatExtensions(requirement.extensions)
                                }}</bdi>
                            </span>

                            <span
                                v-if="requirement.max_size_kb !== null"
                                class="inline-flex items-center rounded-md border border-border/70 bg-muted/30 px-2 py-0.5 font-mono text-[11px] text-muted-foreground"
                            >
                                Max
                                <bdi dir="ltr">{{
                                    formatBytes(
                                        requirement.max_size_kb * 1024,
                                        locale,
                                    )
                                }}</bdi>
                            </span>

                            <span class="text-[11px] text-muted-foreground">
                                •
                                {{
                                    requirement.allows_multiple
                                        ? t('oeuvres.step2.multipleFiles')
                                        : t('oeuvres.step2.singleFile')
                                }}
                            </span>
                        </div>

                        <!-- Advisory Condition Tooltip -->
                        <TooltipProvider
                            v-if="mayNotApply"
                            :delay-duration="150"
                        >
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="mt-1 inline-flex cursor-pointer items-center gap-1 text-xs text-amber-700 underline decoration-dotted underline-offset-2 dark:text-amber-400"
                                    >
                                        <Info class="size-3.5 shrink-0" />
                                        {{
                                            t('oeuvres.step2.mayNotApplyMarker')
                                        }}
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent class="max-w-sm">
                                    <p>
                                        {{ t('oeuvres.step2.conditionHint') }}
                                    </p>
                                    <pre
                                        dir="ltr"
                                        class="mt-1 font-mono text-[11px] whitespace-pre-wrap"
                                        >{{
                                            describeConditions(
                                                requirement.conditions!,
                                            )
                                        }}</pre>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </div>

                <!-- Status Badge -->
                <div class="shrink-0">
                    <!-- Under review the slot reads by the ONDA's decision. -->
                    <span
                        v-if="reviewMode && reviewState"
                        class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold"
                        :class="
                            reviewState === 'to_fix'
                                ? 'border-destructive/30 bg-destructive/10 text-destructive'
                                : reviewState === 'accepted'
                                  ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                  : 'border-border bg-muted/40 text-muted-foreground'
                        "
                        data-testid="slot-review-state"
                        :data-state="reviewState"
                    >
                        <component
                            :is="
                                reviewState === 'to_fix'
                                    ? CircleX
                                    : reviewState === 'accepted'
                                      ? Lock
                                      : Clock
                            "
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        {{ t(`oeuvres.review.slot.${reviewState}`) }}
                    </span>
                    <span
                        v-else-if="state === 'deposited'"
                        class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                    >
                        <CheckCircle2 class="size-3.5" />
                        {{ t(`oeuvres.step2.state.${state}`) }}
                    </span>
                    <span
                        v-else-if="state === 'inProgress'"
                        class="inline-flex items-center gap-1.5 rounded-full border border-onda-blue-500/30 bg-onda-blue-500/10 px-3 py-1 text-xs font-semibold text-onda-blue-700 dark:text-onda-blue-300"
                    >
                        <Loader2 class="size-3.5 animate-spin" />
                        {{ t(`oeuvres.step2.state.${state}`) }}
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 rounded-full border border-dashed border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-medium text-amber-700 dark:text-amber-400"
                    >
                        <CircleDashed class="size-3.5" />
                        {{ t(`oeuvres.step2.state.${state}`) }}
                    </span>
                </div>
            </div>

            <!-- Dropzone for this slot -->
            <Dropzone
                v-if="editable"
                :id="
                    replacementExpected
                        ? replacementDropzoneId(requirement.id)
                        : undefined
                "
                :oeuvre-id="oeuvreId"
                :requirement="requirement"
                :disabled="locked"
                :replacement="replacementExpected"
                :reminder="replacementExpected ? reminder : null"
                compact
            />
            <Dropzone
                v-else-if="takesReplacement"
                :id="replacementDropzoneId(requirement.id)"
                :oeuvre-id="oeuvreId"
                :requirement="requirement"
                :max-files="replacementOpen"
                :reminder="reminder"
                replacement
                compact
            />

            <!-- Uploaded Files in this slot -->
            <div v-if="entries.length > 0" class="space-y-3 pt-1">
                <div
                    class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
                >
                    <FileCheck class="size-3.5" />
                    <!-- Label and count are separate isolates: in RTL a trailing
                         colon or parenthesis next to a number is reordered by
                         the bidi algorithm and lands on the wrong side. -->
                    <span class="inline-flex items-baseline gap-1">
                        <bdi>{{ t('oeuvres.step2.filesForSlot') }}</bdi>
                        <bdi dir="ltr" class="font-mono tabular-nums"
                            >({{ entries.length }})</bdi
                        >
                    </span>
                </div>

                <ul class="space-y-3">
                    <li v-for="entry in entries" :key="entryKey(entry)">
                        <DepositCard
                            :entry="entry"
                            :quota="quota"
                            :editable="editable"
                            :review-mode="reviewMode"
                            can-replace
                            :busy="
                                entry.kind === 'media' &&
                                busyUuid === entry.file.uuid
                            "
                            @pause="emit('pause', $event)"
                            @resume="emit('resume', $event)"
                            @cancel="emit('cancel', $event)"
                            @remove="emit('remove', $event)"
                            @replace="emit('replace', $event)"
                        />
                    </li>
                </ul>
            </div>

            <SlotHistory
                v-if="history && history.length > 0"
                :events="history"
            />
        </CardContent>
    </Card>
</template>
