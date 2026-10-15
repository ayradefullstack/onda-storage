<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FilePlus2 } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { create } from '@/routes/oeuvres';

const { t } = useI18n();

// The three steps of the real deposit flow, in order: the classification
// page creates the oeuvre, its page takes the documents, then submits.
const STEPS = [
    'dashboard.firstRun.step1',
    'dashboard.firstRun.step2',
    'dashboard.firstRun.step3',
] as const;
</script>

<template>
    <section
        class="mx-auto max-w-xl space-y-5 rounded-xl border border-dashed border-border bg-card p-6 text-center shadow-xs sm:p-8"
        aria-labelledby="dashboard-first-run-title"
        data-testid="dashboard-first-run"
    >
        <span
            class="mx-auto flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary"
        >
            <FilePlus2 class="size-6" />
        </span>
        <div class="space-y-1">
            <h2
                id="dashboard-first-run-title"
                class="text-base font-bold tracking-tight text-foreground sm:text-lg"
            >
                {{ t('dashboard.firstRun.title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ t('dashboard.firstRun.body') }}
            </p>
        </div>
        <ol class="mx-auto max-w-sm space-y-2 text-start text-sm">
            <li
                v-for="(step, i) in STEPS"
                :key="step"
                class="flex items-start gap-3"
            >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-foreground tabular-nums"
                    aria-hidden="true"
                    >{{ i + 1 }}</span
                >
                <span class="pt-0.5 text-foreground">{{ t(step) }}</span>
            </li>
        </ol>
        <Button as-child>
            <Link :href="create()" data-testid="first-run-create">
                {{ t('dashboard.firstRun.cta') }}
            </Link>
        </Button>
    </section>
</template>
