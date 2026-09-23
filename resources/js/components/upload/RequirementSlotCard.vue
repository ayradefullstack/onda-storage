<script setup lang="ts">
import {
    CheckCircle2Icon,
    CircleDashedIcon,
    InfoIcon,
    LoaderCircleIcon,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
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
import type { RequirementSlot } from '@/components/upload/requirement';
import {
    describeConditions,
    formatExtensions,
} from '@/components/upload/requirement';
import { formatBytes } from '@/lib/format';

const props = defineProps<{
    requirement: RequirementSlot;
    /** 1-based position, in display_order. */
    position: number;
    oeuvreId: number;
    entries: DepositEntry[];
    quota: { used_bytes: number; limit_bytes: number };
    /**
     * Whether this oeuvre is still the author's to change. False hides the
     * dropzone and the per-file remove control — a submitted deposit is
     * read-only, and the server refuses both regardless.
     */
    editable?: boolean;
}>();

const emit = defineEmits<{
    pause: [id: string];
    resume: [id: string];
    cancel: [id: string];
    remove: [uuid: string];
}>();

const { t, locale } = useI18n();

const state = computed(() =>
    slotStateOf(props.entries, props.requirement.is_required),
);

// A single-file slot refuses a second file (InitUpload does too) while one
// is deposited or on its way; a slot whose file failed can be retried.
const locked = computed(
    () =>
        !props.requirement.allows_multiple &&
        (state.value === 'deposited' || state.value === 'inProgress'),
);

// Not evaluated — see RequirementSlot.conditions. Once the slot holds a
// deposited file the question is moot, so the marker goes.
const mayNotApply = computed(
    () => props.requirement.conditions !== null && state.value !== 'deposited',
);
</script>

<template>
    <Card
        :class="
            state === 'deposited'
                ? 'border-primary/40'
                : state === 'awaiting'
                  ? 'border-dashed'
                  : ''
        "
    >
        <CardContent class="space-y-3 py-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium tabular-nums"
                        aria-hidden="true"
                        >{{ position }}</span
                    >
                    <div class="min-w-0 space-y-1">
                        <h3 class="text-sm leading-6 font-medium">
                            <bdi>{{ requirement.title }}</bdi>
                            <span
                                v-if="requirement.is_required"
                                class="ms-1 text-destructive"
                                :title="t('oeuvres.step2.required')"
                                aria-hidden="true"
                                >*</span
                            >
                            <span
                                v-if="requirement.is_required"
                                class="sr-only"
                                >{{ t('oeuvres.step2.required') }}</span
                            >
                        </h3>
                        <p
                            class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground"
                        >
                            <i18n-t keypath="oeuvres.step2.formats" tag="span">
                                <template #extensions
                                    ><bdi dir="ltr">{{
                                        formatExtensions(requirement.extensions)
                                    }}</bdi></template
                                >
                            </i18n-t>
                            <i18n-t
                                v-if="requirement.max_size_kb !== null"
                                keypath="oeuvres.step2.maxSize"
                                tag="span"
                            >
                                <template #size
                                    ><bdi dir="ltr">{{
                                        formatBytes(
                                            requirement.max_size_kb * 1024,
                                            locale,
                                        )
                                    }}</bdi></template
                                >
                            </i18n-t>
                            <span v-if="!requirement.allows_multiple">{{
                                t('oeuvres.step2.singleFile')
                            }}</span>
                        </p>
                        <TooltipProvider
                            v-if="mayNotApply"
                            :delay-duration="150"
                        >
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 text-xs text-amber-700 underline decoration-dotted underline-offset-2 dark:text-amber-400"
                                    >
                                        <InfoIcon class="size-3.5 shrink-0" />
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

                <Badge
                    :variant="
                        state === 'deposited'
                            ? 'default'
                            : state === 'inProgress'
                              ? 'secondary'
                              : 'outline'
                    "
                    :class="
                        state === 'awaiting'
                            ? 'border-destructive/40 text-destructive'
                            : ''
                    "
                >
                    <CheckCircle2Icon v-if="state === 'deposited'" />
                    <LoaderCircleIcon
                        v-else-if="state === 'inProgress'"
                        class="animate-spin"
                    />
                    <CircleDashedIcon v-else />
                    {{ t(`oeuvres.step2.state.${state}`) }}
                </Badge>
            </div>

            <!-- A frozen deposit shows its files and no way to add to
                 them. InitUpload refuses the upload anyway; this is so the
                 author is not offered something the server will reject. -->
            <Dropzone
                v-if="editable"
                :oeuvre-id="oeuvreId"
                :requirement="requirement"
                :disabled="locked"
                compact
            />

            <ul v-if="entries.length > 0" class="space-y-3">
                <li v-for="entry in entries" :key="entryKey(entry)">
                    <DepositCard
                        :entry="entry"
                        :quota="quota"
                        :editable="editable"
                        @pause="emit('pause', $event)"
                        @resume="emit('resume', $event)"
                        @cancel="emit('cancel', $event)"
                        @remove="emit('remove', $event)"
                    />
                </li>
            </ul>
        </CardContent>
    </Card>
</template>
