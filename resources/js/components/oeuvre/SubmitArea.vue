<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    Info,
    Lock,
    Send,
    ShieldCheck,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

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
    <Card
        class="overflow-hidden transition-all duration-300"
        :class="[
            !submission.is_open
                ? 'border-border/80 bg-card/60'
                : submission.can_submit
                  ? 'border-emerald-500/40 bg-gradient-to-br from-emerald-500/5 via-card to-card shadow-onda-card ring-1 ring-emerald-500/20'
                  : 'border-border/80 bg-card/70',
        ]"
    >
        <CardContent class="space-y-4 p-5 sm:p-6">
            <!-- Frozen: submitted, under review, or registered -->
            <template v-if="!submission.is_open">
                <div class="flex items-start gap-3.5">
                    <div
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-border/80 bg-muted/60 text-muted-foreground"
                    >
                        <Lock class="size-5" />
                    </div>
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <h2
                                class="text-sm font-semibold tracking-tight text-foreground"
                            >
                                {{ t('oeuvres.frozen.title') }}
                            </h2>
                            <span
                                class="rounded bg-muted px-2 py-0.5 text-[10px] font-semibold text-muted-foreground uppercase"
                            >
                                {{ t(`oeuvres.status.${status}`) }}
                            </span>
                        </div>
                        <p
                            class="text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('oeuvres.frozen.body') }}
                        </p>
                    </div>
                </div>
            </template>

            <!-- Active / Editable Submission Gate -->
            <template v-else>
                <div
                    class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div class="flex items-start gap-3.5">
                        <div
                            :class="[
                                'flex size-10 shrink-0 items-center justify-center rounded-xl border transition-colors',
                                submission.can_submit
                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 shadow-xs dark:text-emerald-400'
                                    : 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
                            ]"
                        >
                            <CheckCircle2
                                v-if="submission.can_submit"
                                class="size-5"
                            />
                            <AlertTriangle v-else class="size-5" />
                        </div>
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <h2
                                    class="text-sm font-semibold tracking-tight text-foreground"
                                >
                                    {{ t('oeuvres.gate.title') }}
                                </h2>
                                <span
                                    v-if="submission.can_submit"
                                    class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300"
                                >
                                    Prêt pour dépôt
                                </span>
                            </div>
                            <p
                                class="text-xs leading-relaxed text-muted-foreground"
                            >
                                {{
                                    submission.can_submit
                                        ? t('oeuvres.gate.readyBody')
                                        : t('oeuvres.gate.blockedBody')
                                }}
                            </p>
                        </div>
                    </div>

                    <!-- Direct Action Button when ready -->
                    <Button
                        v-if="submission.can_submit && editable"
                        class="h-11 shrink-0 cursor-pointer gap-2 bg-emerald-600 px-5 text-xs font-semibold text-white shadow-onda-card hover:bg-emerald-700 dark:bg-emerald-500 dark:hover:bg-emerald-600"
                        @click="$emit('submit')"
                    >
                        <Send class="size-4 rtl:rotate-180" />
                        <span>{{ t('oeuvres.table.submit') }}</span>
                    </Button>
                </div>

                <!-- Every blocker at once, each naming its file or slot -->
                <div
                    v-if="submission.blockers.length > 0"
                    class="space-y-2 rounded-xl border border-amber-500/25 bg-amber-500/5 p-4 text-xs"
                >
                    <p class="font-semibold text-amber-800 dark:text-amber-300">
                        Éléments requis avant de pouvoir soumettre :
                    </p>
                    <ul class="space-y-2">
                        <li
                            v-for="(blocker, i) in submission.blockers"
                            :key="`${blocker.code}-${i}`"
                            class="flex items-start gap-2.5 leading-relaxed text-foreground"
                        >
                            <AlertTriangle
                                class="mt-0.5 size-3.5 shrink-0 text-amber-600 dark:text-amber-400"
                            />
                            <span>{{ reasonText(blocker) }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Advisories: conditional slots left empty. Never blockers. -->
                <div
                    v-if="submission.advisories.length > 0"
                    class="space-y-2 rounded-xl border border-border/80 bg-muted/40 p-4 text-xs"
                >
                    <div
                        class="flex items-center gap-2 font-semibold text-foreground"
                    >
                        <Info class="size-3.5 text-muted-foreground" />
                        <span>{{ t('oeuvres.gate.advisoryTitle') }}</span>
                    </div>
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('oeuvres.gate.advisoryBody') }}
                    </p>
                    <ul
                        class="ms-1 space-y-1 border-s-2 border-border/70 ps-3 font-mono text-[11px] text-muted-foreground"
                    >
                        <li
                            v-for="(advisory, i) in submission.advisories"
                            :key="`${advisory.code}-${i}`"
                        >
                            • <bdi>{{ advisory.params.name }}</bdi>
                        </li>
                    </ul>
                </div>

                <div
                    v-if="!submission.can_submit"
                    class="flex justify-end pt-1"
                >
                    <Button
                        class="h-10 cursor-not-allowed gap-2 rounded-xl text-xs opacity-50"
                        disabled
                    >
                        <Send class="size-3.5 rtl:rotate-180" />
                        {{ t('oeuvres.table.submit') }}
                    </Button>
                </div>
            </template>
        </CardContent>
    </Card>
</template>
