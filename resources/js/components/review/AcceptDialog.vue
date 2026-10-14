<script setup lang="ts">
import { AlertTriangle, CheckCircle2, ShieldCheck, XCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { AppDialog } from '@/components/ui/app-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { approve } from '@/routes/admin/oeuvres';
import { acceptBlockers, checksFromProblems, postDecision } from './decisions';
import type { Checks, Outcome, Problem } from './decisions';

/**
 * "Accepter le dépôt": a checklist of what a registration requires, then
 * one button. Registration is final, so the dialog shows what it is about
 * to make permanent — whose deposit, how many files — before the click.
 *
 * The checklist is the page's advisory view; the server asks the same gate
 * again. If it refuses (a file failed since the page loaded), its exact
 * list replaces the checklist here and the button goes away with it —
 * never a generic error. Every other outcome is the parent's to announce.
 */
const props = defineProps<{
    open: boolean;
    oeuvre: {
        uuid: string;
        label: string;
        author: string | null;
        reference: string;
    };
    checks: Checks;
    /** Empty conditional documents: shown, never blocking. */
    advisories?: number;
}>();

const emit = defineEmits<{
    'update:open': [open: boolean];
    outcome: [outcome: Outcome];
}>();

const { t } = useI18n();
const busy = ref(false);
const refused = ref<Problem[] | null>(null);

watch(
    () => props.open,
    (open) => {
        if (open) {
            refused.value = null;
        }
    },
);

const checks = computed<Checks>(() =>
    refused.value === null
        ? props.checks
        : checksFromProblems(refused.value, props.checks.files),
);
const blockers = computed(() => acceptBlockers(checks.value));
const problems = computed<Problem[]>(() => checks.value.problems ?? []);

const requiredOk = computed(() => checks.value.missing_required === 0);
const filesOk = computed(
    () =>
        checks.value.files > 0 &&
        checks.value.pending === 0 &&
        checks.value.blocked === 0,
);

const blockerText = (key: string, count: number): string =>
    t(`review.blockers.${key}`, { count }, count);

const filesDetail = computed(() =>
    blockers.value
        .filter((blocker) => blocker.key !== 'missingRequired')
        .map((blocker) => blockerText(blocker.key, blocker.count))
        .join(' · '),
);

const problemText = (problem: Problem): string =>
    t(`review.problems.${problem.code}`, {
        name: String(problem.params.name ?? ''),
        status: t(`media.status.${String(problem.params.status ?? 'failed')}`),
    });

async function confirm(): Promise<void> {
    if (busy.value || blockers.value.length > 0) {
        return;
    }

    busy.value = true;
    const outcome = await postDecision(approve(props.oeuvre.uuid).url);
    busy.value = false;

    if (outcome.kind === 'refused') {
        refused.value = outcome.problems;

        return;
    }

    emit('update:open', false);
    emit('outcome', outcome);
}
</script>

<template>
    <AppDialog
        :open="open"
        size="md"
        :busy="busy"
        :title="t('review.accept.title')"
        :description="t('review.accept.description')"
        @update:open="(value: boolean) => emit('update:open', value)"
    >
        <template #icon>
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-emerald-500/20 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
            >
                <ShieldCheck class="size-5" />
            </div>
        </template>

        <div class="space-y-4" data-testid="accept-dialog">
            <ul class="space-y-2 text-sm" data-testid="accept-checklist">
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
                    <div>
                        <p class="font-medium text-foreground">
                            {{ t('review.accept.checkRequired') }}
                        </p>
                        <p v-if="!requiredOk" class="text-xs text-destructive">
                            {{
                                blockerText(
                                    'missingRequired',
                                    checks.missing_required,
                                )
                            }}
                        </p>
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
                    <div>
                        <p class="font-medium text-foreground">
                            {{ t('review.accept.checkReady') }}
                        </p>
                        <p v-if="!filesOk" class="text-xs text-destructive">
                            {{ filesDetail }}
                        </p>
                    </div>
                </li>
            </ul>

            <div
                v-if="blockers.length > 0 && problems.length > 0"
                role="alert"
                class="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm"
                data-testid="accept-problems"
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

            <dl
                class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-lg border bg-muted/40 p-3 text-sm"
            >
                <dt class="text-muted-foreground">
                    {{ t('review.accept.reference') }}
                </dt>
                <dd class="min-w-0 font-medium">
                    <bdi class="block truncate">{{ oeuvre.label }}</bdi>
                    <bdi
                        dir="ltr"
                        class="block truncate font-mono text-xs font-normal text-muted-foreground"
                        >{{ oeuvre.reference }}</bdi
                    >
                </dd>
                <dt class="text-muted-foreground">
                    {{ t('review.accept.author') }}
                </dt>
                <dd class="min-w-0 truncate font-medium">
                    <bdi>{{ oeuvre.author ?? '—' }}</bdi>
                </dd>
                <dt class="text-muted-foreground">
                    {{ t('review.accept.files') }}
                </dt>
                <dd class="font-medium tabular-nums">
                    <bdi dir="ltr">{{ checks.files }}</bdi>
                </dd>
            </dl>

            <p
                v-if="advisories && advisories > 0"
                class="text-xs text-muted-foreground"
            >
                {{
                    t(
                        'review.accept.advisories',
                        { count: advisories },
                        advisories,
                    )
                }}
            </p>
        </div>

        <template #footer>
            <Button
                variant="outline"
                :disabled="busy"
                @click="emit('update:open', false)"
            >
                {{ t('review.cancel') }}
            </Button>
            <Button
                variant="success"
                data-testid="accept-confirm"
                :disabled="busy || blockers.length > 0"
                @click="confirm"
            >
                <Spinner v-if="busy" class="size-4" />
                <ShieldCheck v-else class="size-4" />
                {{ t('review.accept.confirm') }}
            </Button>
        </template>
    </AppDialog>
</template>
