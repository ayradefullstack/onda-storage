<script setup lang="ts">
import {
    AlertTriangleIcon,
    CheckCircle2Icon,
    InfoIcon,
    LockIcon,
    SendIcon,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

/**
 * The end of step 2: submit, or the complete list of reasons you cannot.
 *
 * Three states, and only three:
 *
 *  - the deposit is frozen (`is_open` false) — it says so and offers
 *    nothing, because nothing is on offer;
 *  - the gate passes — the button, and the dialog behind it spells out
 *    what submission costs;
 *  - the gate refuses — EVERY blocker, each naming its file or slot. Not
 *    the first one found: an author told "one more problem" five times in
 *    a row stops trusting the button.
 *
 * Advisories are drawn apart from blockers, in a quieter tone. They are
 * the empty *conditional* required slots, which do not block — see
 * SubmissionGate for why an author who used no sample must still be able
 * to submit.
 */
interface SubmissionReason {
    code: string;
    params: Record<string, string | number | null>;
    message: string;
}

defineProps<{
    submission: {
        can_submit: boolean;
        blockers: SubmissionReason[];
        advisories: SubmissionReason[];
        is_open: boolean;
    };
    status: string;
    editable: boolean;
    reasonText: (reason: SubmissionReason) => string;
}>();

defineEmits<{ submit: [] }>();

const { t } = useI18n();
</script>

<template>
    <Card>
        <CardContent class="space-y-4 py-6">
            <!-- Frozen: submitted, under review, or registered. -->
            <template v-if="!submission.is_open">
                <div class="flex items-start gap-3">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-border/80 bg-muted text-muted-foreground"
                    >
                        <LockIcon class="size-4" />
                    </div>
                    <div class="space-y-1">
                        <h2 class="text-sm font-semibold">
                            {{ t('oeuvres.frozen.title') }}
                        </h2>
                        <p class="text-xs leading-relaxed text-muted-foreground">
                            {{ t('oeuvres.frozen.body') }}
                        </p>
                        <p class="pt-1 text-xs font-medium">
                            {{ t(`oeuvres.status.${status}`) }}
                        </p>
                    </div>
                </div>
            </template>

            <template v-else>
                <div class="flex items-start gap-3">
                    <div
                        :class="[
                            'flex size-9 shrink-0 items-center justify-center rounded-lg border',
                            submission.can_submit
                                ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                : 'border-amber-500/25 bg-amber-500/10 text-amber-600 dark:text-amber-400',
                        ]"
                    >
                        <CheckCircle2Icon
                            v-if="submission.can_submit"
                            class="size-4"
                        />
                        <AlertTriangleIcon v-else class="size-4" />
                    </div>
                    <div class="space-y-1">
                        <h2 class="text-sm font-semibold">
                            {{ t('oeuvres.gate.title') }}
                        </h2>
                        <p class="text-xs leading-relaxed text-muted-foreground">
                            {{
                                submission.can_submit
                                    ? t('oeuvres.gate.readyBody')
                                    : t('oeuvres.gate.blockedBody')
                            }}
                        </p>
                    </div>
                </div>

                <!-- Every blocker at once, each naming its file or slot. -->
                <ul
                    v-if="submission.blockers.length > 0"
                    class="space-y-2 rounded-xl border border-amber-500/25 bg-amber-500/5 p-4 text-xs"
                >
                    <li
                        v-for="(blocker, i) in submission.blockers"
                        :key="`${blocker.code}-${i}`"
                        class="flex items-start gap-2.5 leading-relaxed"
                    >
                        <AlertTriangleIcon
                            class="mt-0.5 size-3.5 shrink-0 text-amber-600 dark:text-amber-400"
                        />
                        <span class="text-foreground">{{
                            reasonText(blocker)
                        }}</span>
                    </li>
                </ul>

                <!-- Advisories: conditional slots left empty. Never blockers. -->
                <div
                    v-if="submission.advisories.length > 0"
                    class="space-y-2 rounded-xl border border-border/80 bg-muted/40 p-4 text-xs"
                >
                    <p
                        class="flex items-center gap-2 font-semibold text-foreground"
                    >
                        <InfoIcon class="size-3.5" />
                        {{ t('oeuvres.gate.advisoryTitle') }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ t('oeuvres.gate.advisoryBody') }}
                    </p>
                    <ul
                        class="ms-1 space-y-1 border-s-2 border-border/70 ps-4 text-muted-foreground"
                    >
                        <li
                            v-for="(advisory, i) in submission.advisories"
                            :key="`${advisory.code}-${i}`"
                        >
                            <bdi>{{ advisory.params.name }}</bdi>
                        </li>
                    </ul>
                </div>

                <div class="flex justify-end pt-1">
                    <Button
                        class="cursor-pointer gap-2 rounded-xl"
                        :disabled="!submission.can_submit || !editable"
                        @click="$emit('submit')"
                    >
                        <SendIcon class="size-4" />
                        {{ t('oeuvres.table.submit') }}
                    </Button>
                </div>
            </template>
        </CardContent>
    </Card>
</template>
