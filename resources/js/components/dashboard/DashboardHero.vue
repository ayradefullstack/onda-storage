<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { FileSpreadsheet, PlusCircle, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

const { t } = useI18n();
const page = usePage();

const user = computed(() => page.props.auth.user);

const authorIpiNumber = computed(() => {
    // Generate a consistent pseudo IPI code for authenticated user
    const userId = user.value?.id ?? 1;

    return `DZ-2026-${String(userId).padStart(4, '0')}-ONDA`;
});

const emit = defineEmits<{
    (e: 'open-deposit'): void;
    (e: 'open-statement'): void;
}>();
</script>

<template>
    <div
        class="relative overflow-hidden rounded-2xl border border-onda-blue-500/20 bg-gradient-to-br from-onda-blue-50/60 via-card to-card p-5 shadow-xs sm:p-7 dark:border-border/80 dark:from-onda-blue-950/30 dark:via-card dark:to-card"
    >
        <!-- Background decorative ambient blur -->
        <div
            class="pointer-events-none absolute -end-16 -top-16 size-72 rounded-full bg-gradient-to-br from-onda-blue-400/15 to-onda-teal-400/15 blur-3xl dark:from-onda-blue-600/20 dark:to-onda-teal-600/20"
        />
        <div
            class="pointer-events-none absolute -start-16 -bottom-16 size-60 rounded-full bg-onda-teal-400/10 blur-2xl dark:bg-onda-teal-500/15"
        />

        <div
            class="relative z-10 flex flex-col justify-between gap-6 lg:flex-row lg:items-center"
        >
            <!-- Left / Start: Greeting, Badge, IPI, Sovereignty Notice -->
            <div class="max-w-2xl space-y-3">
                <!-- Badges Row -->
                <div class="flex flex-wrap items-center gap-2">
                    <Badge
                        variant="secondary"
                        class="gap-1.5 rounded-full border-onda-blue-500/20 bg-onda-blue-600/10 px-3 py-1 text-xs font-semibold text-onda-blue-700 shadow-xs dark:bg-onda-blue-500/20 dark:text-onda-blue-300"
                    >
                        <ShieldCheck
                            class="size-3.5 text-onda-blue-600 dark:text-onda-blue-400"
                        />
                        <span>{{ t('dashboard.memberStatus') }}</span>
                    </Badge>

                    <Badge
                        variant="outline"
                        class="gap-1 rounded-full border-border/80 bg-background/60 px-2.5 py-1 font-mono text-[11px] text-muted-foreground backdrop-blur-xs"
                    >
                        <span
                            class="font-sans text-[10px] text-muted-foreground/80"
                            >{{ t('dashboard.ipiLabel') }}:</span
                        >
                        <strong class="font-bold text-foreground">{{
                            authorIpiNumber
                        }}</strong>
                    </Badge>

                    <span
                        class="hidden items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-medium text-emerald-600 sm:inline-flex dark:bg-emerald-500/20 dark:text-emerald-400"
                    >
                        <span
                            class="size-1.5 animate-pulse rounded-full bg-emerald-500"
                        />
                        {{ t('dashboard.sovereignBadge') }}
                    </span>
                </div>

                <!-- Main Greeting Heading -->
                <div class="space-y-1">
                    <h1
                        class="text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl"
                    >
                        {{
                            t('dashboard.welcome', {
                                name: user?.name || 'Mohamed Benali',
                            })
                        }}
                    </h1>
                    <p class="text-sm leading-relaxed text-muted-foreground">
                        {{ t('dashboard.heroSubtitle') }}
                    </p>
                </div>
            </div>

            <!-- Right / End: Quick Call-To-Action Buttons -->
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                <Button
                    variant="outline"
                    class="h-10 cursor-pointer gap-2 rounded-lg border-border bg-card px-4 text-xs font-semibold text-foreground shadow-xs transition-colors hover:bg-accent"
                    @click="emit('open-statement')"
                >
                    <FileSpreadsheet
                        class="size-4 text-onda-teal-600 dark:text-onda-teal-400"
                    />
                    <span>{{ t('dashboard.viewStatementBtn') }}</span>
                </Button>

                <Button
                    class="h-10 cursor-pointer gap-2 rounded-lg px-5 text-xs font-semibold shadow-xs"
                    @click="emit('open-deposit')"
                >
                    <PlusCircle class="size-4" />
                    <span>{{ t('dashboard.newDepositBtn') }}</span>
                </Button>
            </div>
        </div>
    </div>
</template>
