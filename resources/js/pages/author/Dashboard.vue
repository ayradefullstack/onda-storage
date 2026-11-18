<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { PlusCircle } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import DashboardActions from '@/components/dashboard/DashboardActions.vue';
import DashboardDecisions from '@/components/dashboard/DashboardDecisions.vue';
import DashboardFirstRun from '@/components/dashboard/DashboardFirstRun.vue';
import DashboardRecentWorks from '@/components/dashboard/DashboardRecentWorks.vue';
import DashboardStatusCards from '@/components/dashboard/DashboardStatusCards.vue';
import DashboardStorage from '@/components/dashboard/DashboardStorage.vue';
import type {
    DashboardActions as Actions,
    DashboardCounts,
    DashboardDecision,
    DashboardQuota,
    DashboardRecent,
} from '@/components/dashboard/types';
import { Button } from '@/components/ui/button';
import { vReveal } from '@/composables/useReveal';
import { dashboard } from '@/routes/author';
import { create } from '@/routes/oeuvres';

/**
 * The author's home. Everything below comes from Author\DashboardController,
 * scoped to the signed-in author; a block with nothing real to show is not
 * rendered at all, rather than padded with placeholders.
 */
const props = defineProps<{
    counts: DashboardCounts;
    actions: Actions;
    recent: DashboardRecent[];
    quota: DashboardQuota | null;
    decisions: DashboardDecision[];
}>();

defineOptions({
    layout: {
        // Untranslated like every other page's: Breadcrumbs.vue prints the
        // title as given, and defineOptions cannot reach vue-i18n.
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const { t } = useI18n();
const page = usePage();

const name = computed(() => page.props.auth.user?.name ?? '');

// A first-time author has no oeuvre at all — soft-deleted ones do not
// count. One who has only drafts is already under way, and the actions
// block guides them better than the three-step introduction.
const firstRun = computed(() => (props.counts.all ?? 0) === 0);
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <div class="mx-auto w-full max-w-7xl flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0 space-y-1">
                <h1
                    class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                    data-testid="dashboard-greeting"
                >
                    <template v-if="name">
                        {{ t('dashboard.welcome', { name }) }}
                    </template>
                    <template v-else>{{ t('dashboard.title') }}</template>
                </h1>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    {{ t('dashboard.intro') }}
                </p>
            </div>
            <Button v-if="!firstRun" as-child class="w-full shrink-0 sm:w-auto">
                <Link :href="create()" data-testid="new-deposit">
                    <PlusCircle class="size-4" />
                    {{ t('dashboard.newDepositBtn') }}
                </Link>
            </Button>
        </header>

        <DashboardFirstRun v-if="firstRun" />

        <template v-else>
            <DashboardStatusCards :counts="counts" />

            <DashboardActions
                v-if="actions.items.length > 0"
                v-reveal
                :actions="actions"
            />

            <div
                :class="[
                    'grid grid-cols-1 gap-6',
                    quota !== null && 'lg:grid-cols-3',
                ]"
            >
                <DashboardRecentWorks
                    v-reveal
                    :recent="recent"
                    :class="quota !== null && 'lg:col-span-2'"
                />
                <DashboardStorage
                    v-if="quota !== null"
                    v-reveal
                    :quota="quota"
                    class="self-start"
                />
            </div>

            <DashboardDecisions
                v-if="decisions.length > 0"
                v-reveal
                :decisions="decisions"
            />
        </template>
    </div>
</template>
