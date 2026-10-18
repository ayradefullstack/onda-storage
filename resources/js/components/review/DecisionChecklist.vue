<script setup lang="ts">
import { CheckCircle2, Info, XCircle } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { DecisionSummary } from './decisions';

/**
 * What a registration requires, by name: the required documents, every
 * file ready, and — never blocking — the conditional documents left empty,
 * which are the officer's call. The same SubmissionGate the approve route
 * asks again under a lock produced every line.
 */
const props = defineProps<{
    checklist: DecisionSummary['checklist'];
}>();

const { t } = useI18n();

const requiredOk = computed(() => props.checklist.requiredMissing.length === 0);
const notAccepted = computed(() => props.checklist.filesNotAccepted ?? []);
const acceptedOk = computed(
    () => !props.checklist.noFiles && notAccepted.value.length === 0,
);
const filesOk = computed(
    () =>
        !props.checklist.noFiles && props.checklist.filesNotReady.length === 0,
);
</script>

<template>
    <div class="space-y-3" data-testid="decision-checklist">
        <ul class="space-y-2.5 text-sm">
            <li
                class="flex items-start gap-2.5"
                data-testid="check-required"
                :data-ok="requiredOk"
            >
                <CheckCircle2
                    v-if="requiredOk"
                    class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                />
                <XCircle
                    v-else
                    class="mt-0.5 size-4 shrink-0 text-destructive"
                />
                <div class="min-w-0">
                    <p class="font-medium text-foreground">
                        {{ t('review.accept.checkRequired') }}
                    </p>
                    <ul
                        v-if="!requiredOk"
                        class="mt-1 list-disc space-y-0.5 ps-5 text-xs text-destructive"
                    >
                        <li
                            v-for="(label, i) in checklist.requiredMissing"
                            :key="`${label}-${i}`"
                        >
                            <bdi>{{ label }}</bdi>
                        </li>
                    </ul>
                </div>
            </li>
            <li
                class="flex items-start gap-2.5"
                data-testid="check-ready"
                :data-ok="filesOk"
            >
                <CheckCircle2
                    v-if="filesOk"
                    class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                />
                <XCircle
                    v-else
                    class="mt-0.5 size-4 shrink-0 text-destructive"
                />
                <div class="min-w-0">
                    <p class="font-medium text-foreground">
                        {{ t('review.accept.checkReady') }}
                    </p>
                    <p
                        v-if="checklist.noFiles"
                        class="text-xs text-destructive"
                    >
                        {{ t('review.blockers.noFiles') }}
                    </p>
                    <ul
                        v-if="checklist.filesNotReady.length > 0"
                        class="mt-1 space-y-0.5 text-xs"
                    >
                        <li
                            v-for="(file, i) in checklist.filesNotReady"
                            :key="`${file.name}-${i}`"
                            class="flex min-w-0 items-center gap-1.5 text-destructive"
                        >
                            <bdi
                                dir="ltr"
                                class="truncate"
                                :title="file.name"
                                >{{ file.name }}</bdi
                            >
                            <span class="shrink-0 text-muted-foreground">
                                — {{ t(`media.status.${file.status}`) }}
                            </span>
                        </li>
                    </ul>
                </div>
            </li>
            <li
                class="flex items-start gap-2.5"
                data-testid="check-accepted"
                :data-ok="acceptedOk"
            >
                <CheckCircle2
                    v-if="acceptedOk"
                    class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                />
                <XCircle
                    v-else
                    class="mt-0.5 size-4 shrink-0 text-destructive"
                />
                <div class="min-w-0">
                    <p class="font-medium text-foreground">
                        {{ t('review.accept.checkAccepted') }}
                    </p>
                    <ul
                        v-if="notAccepted.length > 0"
                        class="mt-1 space-y-0.5 text-xs"
                    >
                        <li
                            v-for="(file, i) in notAccepted"
                            :key="`${file.name}-${i}`"
                            class="flex min-w-0 items-center gap-1.5 text-destructive"
                            data-testid="check-not-accepted"
                        >
                            <bdi
                                dir="ltr"
                                class="truncate"
                                :title="file.name"
                                >{{ file.name }}</bdi
                            >
                            <span class="shrink-0 text-muted-foreground">
                                —
                                {{
                                    t(`review.file.status.${file.reviewStatus}`)
                                }}
                            </span>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>

        <div
            v-if="checklist.conditionalMissing.length > 0"
            class="flex items-start gap-2 rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground"
            data-testid="check-conditional"
        >
            <Info class="mt-0.5 size-3.5 shrink-0" />
            <div class="min-w-0">
                <p>
                    {{
                        t(
                            'review.accept.advisories',
                            { count: checklist.conditionalMissing.length },
                            checklist.conditionalMissing.length,
                        )
                    }}
                </p>
                <p class="mt-0.5 text-foreground/80">
                    <bdi>{{ checklist.conditionalMissing.join(' · ') }}</bdi>
                </p>
            </div>
        </div>
    </div>
</template>
