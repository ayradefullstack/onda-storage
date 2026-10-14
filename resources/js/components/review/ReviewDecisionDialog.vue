<script setup lang="ts">
import {
    AlertTriangle,
    ArrowLeft,
    Ban,
    ExternalLink,
    PlayCircle,
    RotateCcw,
    ShieldCheck,
    UserCheck,
    UserRoundCog,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import StatusBadge from '@/components/admin/StatusBadge.vue';
import { oeuvreLabel } from '@/components/oeuvre/label';
import { AppDialog } from '@/components/ui/app-dialog';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import {
    approve,
    decision,
    reject,
    review,
    show as oeuvreShow,
} from '@/routes/admin/oeuvres';
import DecisionChecklist from './DecisionChecklist.vue';
import { fetchSummary, postDecision, reasonState } from './decisions';
import type {
    AllowedDecision,
    DecisionSummary,
    Outcome,
    Problem,
} from './decisions';
import RejectReasonField from './RejectReasonField.vue';
import { useKeyboardInset } from './useKeyboardInset';

/**
 * Every decision on one deposit, in one dialog: the table row's and the
 * oeuvre page's "Examiner" both open this.
 *
 * It shows what the server says and nothing it worked out itself. On open
 * it fetches GET admin/oeuvres/{oeuvre}/decision, and the footer offers
 * exactly that summary's `allowed` (plus a greyed "Accepter" when the
 * checklist is what refuses it). After every answer from the server it
 * fetches the summary again, so a conflict turns into the state it
 * describes — a holder and a take-over, or a decision already made.
 *
 *   open_review  "Commencer l'examen" — stays open; Accept/Reject appear.
 *   take_over    "Reprendre l'examen" behind an inline confirm.
 *   approve      "Accepter le dépôt" — closes on success.
 *   reject       "Rejeter" — the reject screen, in this same dialog;
 *                "Retour" keeps the text.
 *
 * `changed` tells the page its data is stale (a status moved, or might
 * have): the page reloads only what it shows, silently.
 */
const props = defineProps<{
    open: boolean;
    /** The oeuvre's uuid; null while no row has been chosen. */
    oeuvre: string | null;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    changed: [];
}>();

type Notice = { tone: 'error' | 'warning' | 'info'; text: string };

const { t, locale } = useI18n();

useKeyboardInset();

const phase = ref<'loading' | 'ready' | 'error'>('loading');
const loadError = ref<number | null>(null);
const summary = ref<DecisionSummary | null>(null);
const screen = ref<'review' | 'reject'>('review');
const busy = ref<AllowedDecision | null>(null);
const confirmingTakeOver = ref(false);
const notice = ref<Notice | null>(null);
const problems = ref<Problem[]>([]);
const reason = ref('');
const reasonError = ref<string | null>(null);
const reasonField = ref<InstanceType<typeof RejectReasonField> | null>(null);

/** Only the newest summary request may land. */
let generation = 0;

async function load(quiet = false): Promise<void> {
    const uuid = props.oeuvre;

    if (uuid === null) {
        return;
    }

    const mine = ++generation;

    if (!quiet || summary.value === null) {
        phase.value = 'loading';
    }

    const result = await fetchSummary(decision(uuid).url);

    if (mine !== generation) {
        return;
    }

    if (result.kind === 'ok') {
        summary.value = result.summary;
        phase.value = 'ready';

        // Whatever was offered before may not be any more.
        if (!result.summary.allowed.includes('reject')) {
            screen.value = 'review';
        }

        if (!result.summary.allowed.includes('take_over')) {
            confirmingTakeOver.value = false;
        }

        return;
    }

    if (summary.value === null || !quiet) {
        loadError.value = result.status;
        phase.value = 'error';
    } else {
        notice.value = { tone: 'error', text: t('review.toast.failed') };
    }
}

function reset(): void {
    generation++;
    summary.value = null;
    phase.value = 'loading';
    loadError.value = null;
    screen.value = 'review';
    busy.value = null;
    confirmingTakeOver.value = false;
    notice.value = null;
    problems.value = [];
    reason.value = '';
    reasonError.value = null;
}

/** The deposit the current state belongs to. */
let shown: string | null = null;

watch(
    () => [props.open, props.oeuvre] as const,
    ([open, uuid]) => {
        if (!open || uuid === null) {
            return;
        }

        // Reopening the same deposit keeps an unfinished reason; another
        // deposit starts clean. Either way the summary is fetched again:
        // it may have changed while the dialog was closed.
        if (uuid !== shown) {
            reset();
            shown = uuid;
        } else {
            notice.value = null;
            problems.value = [];
            screen.value = 'review';
        }

        void load();
    },
    { immediate: true },
);

const offers = (action: AllowedDecision): boolean =>
    summary.value?.allowed.includes(action) ?? false;
const approveShown = computed(
    () =>
        offers('approve') ||
        (summary.value?.blocked.includes('approve') ?? false),
);
const approveBlocked = computed(
    () => summary.value?.blocked.includes('approve') ?? false,
);
const nothingToDo = computed(
    () =>
        summary.value !== null &&
        summary.value.allowed.length === 0 &&
        summary.value.blocked.length === 0,
);

/** Why "Accepter" is greyed, in counts — the names are in the checklist. */
const approveBlockedReason = computed(() => {
    const checklist = summary.value?.checklist;

    if (!checklist) {
        return '';
    }

    const parts: string[] = [];
    const failed = checklist.filesNotReady.filter((file) =>
        ['failed', 'quarantined'].includes(file.status),
    ).length;
    const pending = checklist.filesNotReady.length - failed;

    if (checklist.noFiles) {
        parts.push(t('review.blockers.noFiles'));
    }

    if (pending > 0) {
        parts.push(
            t('review.blockers.pendingFiles', { count: pending }, pending),
        );
    }

    if (failed > 0) {
        parts.push(
            t('review.blockers.blockedFiles', { count: failed }, failed),
        );
    }

    const missing = checklist.requiredMissing.length;

    if (missing > 0) {
        parts.push(
            t('review.blockers.missingRequired', { count: missing }, missing),
        );
    }

    return parts.join(' · ');
});

const label = computed(() =>
    summary.value === null
        ? ''
        : oeuvreLabel(
              {
                  uuid: summary.value.uuid,
                  title: summary.value.title,
                  college_name: summary.value.college,
                  created_at: summary.value.createdAt ?? '',
              },
              locale.value,
              t('oeuvres.untitled'),
          ),
);

const reasonOk = computed(() => reasonState(reason.value) === 'ok');

const problemText = (problem: Problem): string =>
    t(`review.problems.${problem.code}`, {
        name: String(problem.params.name ?? ''),
        status: t(`media.status.${String(problem.params.status ?? 'failed')}`),
    });

function conflictNotice(
    outcome: Extract<Outcome, { kind: 'conflict' }>,
): Notice {
    const { conflict } = outcome;

    if (conflict.kind === 'decided') {
        return {
            tone: 'error',
            text:
                conflict.by === null
                    ? t('review.toast.decidedUnknown')
                    : t('review.toast.decided', {
                          name: conflict.by,
                          date:
                              conflict.at === null
                                  ? '—'
                                  : formatDate(conflict.at, locale.value),
                      }),
        };
    }

    if (conflict.kind === 'held' && conflict.by !== null) {
        return {
            tone: 'warning',
            text: t('review.toast.held', { name: conflict.by }),
        };
    }

    return { tone: 'warning', text: t('review.dialog.moved') };
}

const SUCCESS: Record<AllowedDecision, string> = {
    open_review: 'review.toast.started',
    take_over: 'review.toast.takenOver',
    approve: 'review.toast.accepted',
    reject: 'review.toast.rejected',
};

async function act(action: AllowedDecision): Promise<void> {
    const uuid = props.oeuvre;

    if (uuid === null || busy.value !== null) {
        return;
    }

    if (action === 'reject' && !reasonOk.value) {
        reasonField.value?.touch();

        return;
    }

    busy.value = action;
    notice.value = null;
    problems.value = [];
    reasonError.value = null;

    const outcome = await (() => {
        switch (action) {
            case 'open_review':
                return postDecision(review(uuid).url);
            case 'take_over':
                return postDecision(review(uuid).url, { take_over: true });
            case 'approve':
                return postDecision(approve(uuid).url);
            case 'reject':
                return postDecision(reject(uuid).url, {
                    reason: reason.value.trim(),
                });
        }
    })();

    busy.value = null;

    switch (outcome.kind) {
        case 'done':
            if (outcome.replayed) {
                toast.info(t('review.toast.replayed'));
            } else {
                toast.success(t(SUCCESS[action]));
            }

            emit('changed');

            if (action === 'approve' || action === 'reject') {
                reason.value = '';
                emit('update:open', false);

                return;
            }

            // Opening (or taking over) is the start, not the end: stay,
            // and let the fresh summary bring Accept and Reject.
            confirmingTakeOver.value = false;
            await load(true);

            return;
        case 'conflict':
            notice.value = conflictNotice(outcome);
            screen.value = 'review';
            emit('changed');
            await load(true);

            return;
        case 'refused':
            problems.value = outcome.problems;
            screen.value = 'review';
            await load(true);

            return;
        case 'invalid':
            if (action === 'reject' && outcome.errors.reason !== undefined) {
                reasonError.value = outcome.errors.reason[0] ?? null;

                return;
            }

            notice.value = { tone: 'error', text: t('review.toast.invalid') };
            screen.value = 'review';
            emit('changed');
            await load(true);

            return;
        case 'failed':
            notice.value = { tone: 'error', text: t('review.toast.failed') };
    }
}

function onSubmit(): void {
    if (screen.value === 'reject') {
        void act('reject');
    }
}

function close(): void {
    emit('update:open', false);
}
</script>

<template>
    <AppDialog
        :open="open"
        size="lg"
        :busy="busy !== null"
        form
        class="max-sm:bottom-[var(--keyboard-inset,0px)] max-sm:max-h-[calc(100dvh-var(--keyboard-inset,0px)-1rem)]"
        @update:open="(value: boolean) => emit('update:open', value)"
        @submit="onSubmit"
    >
        <template #icon>
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl border"
                :class="
                    screen === 'reject'
                        ? 'border-destructive/20 bg-destructive/10 text-destructive'
                        : 'border-primary/20 bg-primary/10 text-primary'
                "
            >
                <Ban v-if="screen === 'reject'" class="size-5" />
                <ShieldCheck v-else class="size-5" />
            </div>
        </template>
        <template #title>
            <span v-if="screen === 'reject'">{{
                t('review.reject.title')
            }}</span>
            <bdi v-else-if="summary" data-testid="decision-title">{{
                label
            }}</bdi>
            <span v-else>{{ t('review.dialog.title') }}</span>
        </template>
        <template #description>
            <span v-if="!summary">{{ t('review.dialog.loading') }}</span>
            <span v-else class="flex flex-wrap items-center gap-2">
                <bdi
                    dir="ltr"
                    class="truncate font-mono text-xs"
                    data-testid="decision-reference"
                    >{{ summary.reference }}</bdi
                >
                <StatusBadge kind="oeuvre" :status="summary.status" />
            </span>
        </template>

        <!-- Loading -->
        <div
            v-if="phase === 'loading'"
            class="space-y-4"
            role="status"
            :aria-label="t('review.dialog.loading')"
            data-testid="decision-loading"
        >
            <Skeleton class="h-4 w-2/3" />
            <Skeleton class="h-4 w-1/2" />
            <Skeleton class="h-16 w-full" />
            <Skeleton class="h-4 w-1/3" />
        </div>

        <!-- The summary could not be read -->
        <div
            v-else-if="phase === 'error'"
            role="alert"
            class="flex flex-col items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm"
            data-testid="decision-load-error"
        >
            <p class="flex items-center gap-2 font-medium text-destructive">
                <AlertTriangle class="size-4 shrink-0" />
                {{
                    loadError === 403 || loadError === 404
                        ? t('review.dialog.unavailable')
                        : t('review.dialog.loadFailed')
                }}
            </p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-testid="decision-retry"
                @click="load()"
            >
                <RotateCcw class="size-3.5" />
                {{ t('review.dialog.retry') }}
            </Button>
        </div>

        <template v-else-if="summary">
            <!-- What the server just said, when it was not "done" -->
            <div
                v-if="notice"
                :role="notice.tone === 'error' ? 'alert' : 'status'"
                class="mb-4 flex items-start gap-2 rounded-lg border p-3 text-sm"
                :class="
                    notice.tone === 'error'
                        ? 'border-destructive/30 bg-destructive/5 text-destructive'
                        : 'border-amber-500/30 bg-amber-500/10 text-amber-800 dark:text-amber-300'
                "
                data-testid="decision-notice"
                :data-tone="notice.tone"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <p>{{ notice.text }}</p>
            </div>

            <div
                v-if="problems.length > 0"
                role="alert"
                class="mb-4 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm"
                data-testid="decision-problems"
            >
                <p class="flex items-center gap-2 font-medium text-destructive">
                    <AlertTriangle class="size-4 shrink-0" />
                    {{ t('review.accept.problemsTitle') }}
                </p>
                <ul
                    class="mt-2 list-disc space-y-1 ps-6 text-xs text-foreground"
                >
                    <li
                        v-for="(problem, i) in problems"
                        :key="`${problem.code}-${i}`"
                    >
                        <bdi>{{ problemText(problem) }}</bdi>
                    </li>
                </ul>
            </div>

            <!-- Reject screen -->
            <template v-if="screen === 'reject'">
                <p class="mb-3 text-sm text-muted-foreground">
                    {{ t('review.reject.description') }}
                </p>
                <RejectReasonField
                    ref="reasonField"
                    v-model="reason"
                    :disabled="busy !== null"
                    :server-error="reasonError"
                />
            </template>

            <!-- Review screen -->
            <div v-else class="space-y-5" data-testid="review-screen">
                <dl
                    class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-lg border bg-muted/40 p-3 text-sm"
                >
                    <dt class="text-muted-foreground">
                        {{ t('review.accept.author') }}
                    </dt>
                    <dd class="min-w-0 truncate font-medium">
                        <bdi>{{ summary.author?.name ?? '—' }}</bdi>
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('review.dialog.college') }}
                    </dt>
                    <dd class="min-w-0 truncate">
                        <bdi>{{ summary.college ?? '—' }}</bdi>
                    </dd>
                    <dt class="text-muted-foreground">
                        {{ t('review.dialog.submittedAt') }}
                    </dt>
                    <dd>
                        <bdi dir="ltr">{{
                            summary.submittedAt
                                ? formatDate(summary.submittedAt, locale)
                                : '—'
                        }}</bdi>
                    </dd>
                </dl>

                <a
                    :href="oeuvreShow(summary.uuid).url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-primary underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/40"
                    data-testid="decision-open-oeuvre"
                >
                    <ExternalLink class="size-3.5" />
                    {{ t('review.dialog.openOeuvre') }}
                    <span class="sr-only"
                        >({{ t('consult.openInNewTab') }})</span
                    >
                </a>

                <DecisionChecklist :checklist="summary.checklist" />

                <p
                    v-if="summary.holder"
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                    data-testid="decision-holder"
                >
                    <UserCheck class="size-4 shrink-0" />
                    <span v-if="summary.heldByMe">{{
                        t('review.dialog.heldByYou')
                    }}</span>
                    <i18n-t v-else keypath="review.held.other" tag="span">
                        <template #name>
                            <bdi class="font-medium text-foreground">{{
                                summary.holder.name
                            }}</bdi>
                        </template>
                    </i18n-t>
                </p>

                <p
                    v-if="nothingToDo && summary.lastDecision"
                    class="text-sm text-muted-foreground"
                    data-testid="decision-last"
                >
                    {{
                        t('review.dialog.lastDecision', {
                            status: t(
                                `oeuvres.status.${summary.lastDecision.to}`,
                            ),
                            name: summary.lastDecision.by ?? 'ONDA',
                            date: summary.lastDecision.at
                                ? formatDate(summary.lastDecision.at, locale)
                                : '—',
                        })
                    }}
                </p>
                <p
                    v-else-if="nothingToDo"
                    class="text-sm text-muted-foreground"
                    data-testid="decision-nothing"
                >
                    {{ t('review.dialog.nothing') }}
                </p>

                <p
                    v-if="approveBlocked"
                    id="approve-blocked-reason"
                    class="text-xs text-muted-foreground"
                    data-testid="approve-blocked-reason"
                >
                    {{ t('review.dialog.approveBlocked') }}
                    {{ approveBlockedReason }}
                </p>

                <!-- "Reprendre l'examen": said out loud before it happens -->
                <div
                    v-if="confirmingTakeOver && summary.holder"
                    role="alertdialog"
                    aria-labelledby="take-over-question"
                    class="space-y-3 rounded-lg border border-amber-500/30 bg-amber-500/10 p-3"
                    data-testid="take-over-confirm"
                >
                    <p id="take-over-question" class="text-sm text-foreground">
                        {{
                            t('review.dialog.takeOverQuestion', {
                                name: summary.holder.name,
                            })
                        }}
                    </p>
                    <div class="flex flex-wrap justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="busy !== null"
                            @click="confirmingTakeOver = false"
                        >
                            {{ t('review.cancel') }}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            data-testid="take-over-yes"
                            :disabled="busy !== null"
                            @click="act('take_over')"
                        >
                            <Spinner
                                v-if="busy === 'take_over'"
                                class="size-3.5"
                            />
                            <UserRoundCog v-else class="size-3.5" />
                            {{ t('review.dialog.takeOverYes') }}
                        </Button>
                    </div>
                </div>
            </div>
        </template>

        <template #footer>
            <template v-if="screen === 'reject'">
                <Button
                    type="button"
                    variant="outline"
                    data-testid="reject-back"
                    :disabled="busy !== null"
                    @click="screen = 'review'"
                >
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    {{ t('review.dialog.back') }}
                </Button>
                <Button
                    type="submit"
                    variant="destructive"
                    data-testid="reject-confirm"
                    :disabled="busy !== null || !reasonOk"
                >
                    <Spinner v-if="busy === 'reject'" class="size-4" />
                    <Ban v-else class="size-4" />
                    {{ t('review.dialog.confirmReject') }}
                </Button>
            </template>

            <template v-else>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="busy !== null"
                    @click="close"
                >
                    {{ t('ui.close') }}
                </Button>

                <template v-if="phase === 'ready' && summary">
                    <Button
                        v-if="offers('open_review')"
                        type="button"
                        data-testid="decision-open-review"
                        :disabled="busy !== null"
                        @click="act('open_review')"
                    >
                        <Spinner v-if="busy === 'open_review'" class="size-4" />
                        <PlayCircle v-else class="size-4" />
                        {{ t('review.actions.start') }}
                    </Button>

                    <Button
                        v-if="offers('take_over') && !confirmingTakeOver"
                        type="button"
                        variant="outline"
                        data-testid="decision-take-over"
                        :disabled="busy !== null"
                        @click="confirmingTakeOver = true"
                    >
                        <UserRoundCog class="size-4" />
                        {{ t('review.dialog.takeOver') }}
                    </Button>

                    <Button
                        v-if="offers('reject')"
                        type="button"
                        variant="outline"
                        class="text-destructive hover:text-destructive"
                        data-testid="decision-reject"
                        :disabled="busy !== null"
                        @click="screen = 'reject'"
                    >
                        <Ban class="size-4" />
                        {{ t('review.actions.reject') }}
                    </Button>

                    <Button
                        v-if="approveShown"
                        type="button"
                        variant="success"
                        data-testid="decision-approve"
                        :disabled="busy !== null || approveBlocked"
                        :aria-describedby="
                            approveBlocked
                                ? 'approve-blocked-reason'
                                : undefined
                        "
                        :title="
                            approveBlocked ? approveBlockedReason : undefined
                        "
                        @click="act('approve')"
                    >
                        <Spinner v-if="busy === 'approve'" class="size-4" />
                        <ShieldCheck v-else class="size-4" />
                        {{ t('review.accept.confirm') }}
                    </Button>
                </template>
            </template>
        </template>
    </AppDialog>
</template>
