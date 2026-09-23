<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ShieldCheck,
    Sparkles,
    Lock,
    CheckCircle2,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import FullscreenPreloader from '@/components/public/FullscreenPreloader.vue';
import LanguageSwitcher from '@/components/public/LanguageSwitcher.vue';
import OrgLogo from '@/components/public/OrgLogo.vue';
import ThemeToggle from '@/components/public/ThemeToggle.vue';
import { home } from '@/routes';

const { t, locale } = useI18n();

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <!-- Fullscreen ONDA Logo & Orbital Spinner Loading Screen -->
    <FullscreenPreloader :min-duration="650" />

    <div
        class="relative flex min-h-screen flex-col justify-between bg-background text-foreground selection:bg-onda-blue-600 selection:text-white"
    >
        <!-- TOP UTILITY BAR (Floating above both columns) -->
        <header
            class="relative z-30 flex items-center justify-between px-6 py-4 lg:px-12"
        >
            <!-- Back to Home Link -->
            <Link
                :href="home({ locale })"
                class="inline-flex items-center gap-2 rounded-xl border border-border/80 bg-card/80 px-3.5 py-1.5 text-xs font-semibold text-foreground shadow-xs backdrop-blur-md transition-all hover:bg-accent hover:text-onda-blue-600 dark:hover:text-onda-blue-400"
            >
                <ArrowLeft class="size-3.5 rtl:rotate-180" />
                <span>{{ t('auth.backHome') }}</span>
            </Link>

            <!-- Language Switcher & Theme Toggle -->
            <div class="flex items-center gap-2">
                <LanguageSwitcher />
                <ThemeToggle />
            </div>
        </header>

        <!-- MAIN SPLIT GRID -->
        <div
            class="relative z-20 mx-auto grid w-full max-w-7xl flex-1 grid-cols-1 items-center gap-10 px-4 py-6 sm:px-6 lg:grid-cols-12 lg:gap-14 lg:px-8 xl:gap-18"
        >
            <!-- LEFT (LTR) / RIGHT (RTL): INSTITUTIONAL SHOWCASE PANEL -->
            <div
                class="relative hidden min-h-[620px] flex-col justify-between overflow-hidden rounded-3xl border border-slate-800/60 bg-gradient-to-br from-onda-blue-950 via-onda-blue-900 to-onda-teal-950 p-10 text-white shadow-2xl lg:col-span-5 lg:flex xl:col-span-5"
            >
                <!-- Ambient Glow & Watermark -->
                <div
                    class="pointer-events-none absolute -end-24 -top-24 size-80 rounded-full bg-onda-teal-500/15 blur-3xl"
                />
                <div
                    class="pointer-events-none absolute -start-24 -bottom-24 size-80 rounded-full bg-onda-blue-500/20 blur-3xl"
                />

                <!-- Top Brand Area -->
                <div class="relative z-10 space-y-6">
                    <Link :href="home({ locale })" class="inline-block">
                        <OrgLogo
                            class="h-12 w-auto brightness-0 drop-shadow-md invert"
                        />
                    </Link>

                    <div
                        class="inline-flex items-center gap-2 rounded-full border border-onda-teal-400/30 bg-onda-teal-500/20 px-3.5 py-1 text-xs font-semibold text-onda-teal-200"
                    >
                        <span
                            class="size-2 animate-pulse rounded-full bg-onda-teal-400"
                        />
                        <span>{{ t('auth.securePortal') }}</span>
                    </div>

                    <h2
                        class="text-3xl leading-snug font-extrabold tracking-tight"
                    >
                        {{ t('auth.sideHeading') }}
                    </h2>

                    <p class="max-w-md text-sm leading-relaxed text-slate-300">
                        {{ t('auth.sideSubtitle') }}
                    </p>
                </div>

                <!-- Middle Feature Pills -->
                <div class="relative z-10 my-8 space-y-3.5 text-xs font-medium">
                    <div
                        class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/10 p-3.5 shadow-sm backdrop-blur-md"
                    >
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-onda-teal-500 font-bold text-slate-950"
                        >
                            <ShieldCheck class="size-4.5" />
                        </div>
                        <span class="text-slate-100">{{
                            t('auth.pill1')
                        }}</span>
                    </div>

                    <div
                        class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/10 p-3.5 shadow-sm backdrop-blur-md"
                    >
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-onda-blue-400 font-bold text-slate-950"
                        >
                            <Sparkles class="size-4.5" />
                        </div>
                        <span class="text-slate-100">{{
                            t('auth.pill2')
                        }}</span>
                    </div>

                    <div
                        class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/10 p-3.5 shadow-sm backdrop-blur-md"
                    >
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-onda-teal-400 font-bold text-slate-950"
                        >
                            <Lock class="size-4.5" />
                        </div>
                        <span class="text-slate-100">{{
                            t('auth.pill3')
                        }}</span>
                    </div>
                </div>

                <!-- Bottom Sovereign Guarantee Footnote -->
                <div
                    class="relative z-10 flex items-center justify-between border-t border-white/15 pt-5 text-[11px] text-slate-300"
                >
                    <span>Office National des Droits d'Auteur</span>
                    <span class="font-mono"
                        >الجمهورية الجزائرية الديمقراطية الشعبية</span
                    >
                </div>
            </div>

            <!-- RIGHT (LTR) / LEFT (RTL): AUTH CARD -->
            <div class="flex justify-center lg:col-span-7 xl:col-span-7">
                <div class="w-full max-w-[560px]">
                    <div
                        class="relative rounded-3xl border border-border/80 bg-card p-7 shadow-xl backdrop-blur-xs transition-all sm:p-9 lg:p-11 dark:border-slate-800/80 dark:shadow-2xl"
                    >
                        <!-- Mobile Brand Logo -->
                        <div class="mb-6 flex justify-center lg:hidden">
                            <Link :href="home({ locale })">
                                <OrgLogo class="h-10 w-auto" />
                            </Link>
                        </div>

                        <!-- Card Header (if passed via layout props) -->
                        <div
                            v-if="title"
                            class="mb-8 space-y-2 text-center lg:text-start"
                        >
                            <h1
                                class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                            >
                                {{ title }}
                            </h1>
                            <p
                                v-if="description"
                                class="text-xs leading-relaxed text-muted-foreground sm:text-sm"
                            >
                                {{ description }}
                            </p>
                        </div>

                        <!-- Card Slot for Login / Register Form -->
                        <slot />
                    </div>

                    <!-- Footnote notice -->
                    <div
                        class="mt-6 flex items-center justify-center gap-2 text-center text-xs text-muted-foreground"
                    >
                        <CheckCircle2 class="size-3.5 text-onda-teal-500" />
                        <span>{{ t('hero.trust3') }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
