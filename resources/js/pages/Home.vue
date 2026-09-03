<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ShieldCheck,
    FileText,
    Music,
    Video,
    Code,
    Sparkles,
    ArrowRight,
    Search,
    Award,
    CheckCircle2,
    Lock,
    QrCode,
    Calendar,
    Layers,
    Clock,
    Check,
    ArrowUpRight,
} from '@lucide/vue';
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { register, login } from '@/routes';

const { t } = useI18n();

// Cultural Repertoire Search State
const searchQuery = ref('');
const activeFilter = ref('all');

const sampleRepertoireWorks = [
    {
        id: 1,
        title: 'Kassaman (نشيد قسما)',
        author: 'Moufdi Zakaria / Mohamed Fawzi',
        category: 'music',
        categoryName: 'Musique / النشيد الوطني',
        ref: 'DZ-ONDA-0001-PAT',
        status: 'Patrimoine National',
    },
    {
        id: 2,
        title: 'Nedjma (نجمة)',
        author: 'Kateb Yacine (كاتب ياسين)',
        category: 'literature',
        categoryName: 'Littérature',
        ref: 'DZ-ONDA-1956-LIT',
        status: 'Protégé',
    },
    {
        id: 3,
        title: "La Bataille d'Alger (معركة الجزائر)",
        author: 'Yacef Saâdi / Gillo Pontecorvo',
        category: 'cinema',
        categoryName: 'Audiovisuel',
        ref: 'DZ-ONDA-1966-AV',
        status: 'Protégé',
    },
    {
        id: 4,
        title: "Femmes d'Alger dans leur appartement",
        author: 'Assia Djebar (آسيا جبار)',
        category: 'literature',
        categoryName: 'Littérature',
        ref: 'DZ-ONDA-1980-LIT',
        status: 'Protégé',
    },
];

const filteredWorks = computed(() => {
    return sampleRepertoireWorks.filter((work) => {
        const matchesFilter =
            activeFilter.value === 'all' ||
            work.category === activeFilter.value;
        const matchesQuery =
            !searchQuery.value.trim() ||
            work.title
                .toLowerCase()
                .includes(searchQuery.value.toLowerCase()) ||
            work.author
                .toLowerCase()
                .includes(searchQuery.value.toLowerCase()) ||
            work.ref.toLowerCase().includes(searchQuery.value.toLowerCase());

        return matchesFilter && matchesQuery;
    });
});

const categories = [
    {
        key: 'deposit.categories.text',
        Icon: FileText,
        desc: 'Romans, poésies, scénarios, articles',
    },
    {
        key: 'deposit.categories.audio',
        Icon: Music,
        desc: 'Compositions musicales, arrangements, albums',
    },
    {
        key: 'deposit.categories.video',
        Icon: Video,
        desc: 'Films, documentaires, séries, clips vidéo',
    },
    {
        key: 'deposit.categories.software',
        Icon: Code,
        desc: 'Codes sources, architectures, applications',
    },
];

const steps = [
    {
        number: '01',
        titleKey: 'howItWorks.step1.title',
        descriptionKey: 'howItWorks.step1.description',
        icon: Lock,
    },
    {
        number: '02',
        titleKey: 'howItWorks.step2.title',
        descriptionKey: 'howItWorks.step2.description',
        icon: FileText,
    },
    {
        number: '03',
        titleKey: 'howItWorks.step3.title',
        descriptionKey: 'howItWorks.step3.description',
        icon: ShieldCheck,
    },
];

const guarantees = [
    { key: 'guarantees.fileSize', icon: Layers },
    { key: 'guarantees.resumable', icon: Clock },
    { key: 'guarantees.storage', icon: ShieldCheck },
    { key: 'guarantees.checksum', icon: CheckCircle2 },
];
</script>

<template>
    <Head :title="t('hero.title')" />

    <div class="relative overflow-hidden">
        <!-- Ambient Decorative Top Backdrops -->
        <div
            class="pointer-events-none absolute start-1/2 -top-40 -z-10 h-[560px] w-[900px] -translate-x-1/2 rounded-full bg-gradient-to-tr from-onda-blue-500/15 via-onda-teal-400/15 to-transparent blur-[140px] dark:from-onda-blue-600/20 dark:via-onda-teal-500/15"
        />

        <!-- ========================================================================= -->
        <!-- 1. HERO SECTION WITH DYNAMIC CERTIFICATE PREVIEW -->
        <!-- ========================================================================= -->
        <section
            class="relative mx-auto max-w-7xl px-4 pt-12 pb-20 sm:px-6 lg:px-8 lg:pt-16"
        >
            <div class="grid grid-cols-1 items-center gap-12 lg:grid-cols-12">
                <!-- Left Column: Copy & Actions -->
                <div class="space-y-6 text-center lg:col-span-7 lg:text-start">
                    <!-- Official Badge -->
                    <div
                        class="inline-flex items-center gap-2 rounded-full border border-onda-teal-300/80 bg-onda-teal-50/80 px-3.5 py-1 text-xs font-semibold text-onda-teal-900 shadow-sm backdrop-blur-sm dark:border-onda-teal-800/60 dark:bg-onda-teal-950/60 dark:text-onda-teal-300"
                    >
                        <span
                            class="flex size-2 animate-pulse rounded-full bg-onda-teal-500"
                        />
                        <span>{{ t('hero.badge') }}</span>
                    </div>

                    <!-- Main H1 Headline -->
                    <h1
                        class="text-4xl leading-[1.15] font-extrabold tracking-tight text-slate-900 sm:text-5xl lg:text-6xl dark:text-white"
                    >
                        {{ t('hero.title') }}
                    </h1>

                    <!-- Subtitle -->
                    <p
                        class="mx-auto max-w-2xl text-base leading-relaxed text-slate-600 sm:text-lg lg:mx-0 dark:text-slate-300"
                    >
                        {{ t('hero.subtitle') }}
                    </p>

                    <!-- CTAs -->
                    <div
                        class="flex flex-col items-center justify-center gap-3.5 sm:flex-row lg:justify-start"
                    >
                        <Link
                            :href="register()"
                            class="inline-flex h-12 w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-onda-blue-600 px-7 text-sm font-semibold text-white shadow-lg shadow-onda-blue-600/25 transition-all hover:bg-onda-blue-700 hover:shadow-onda-blue-600/40 sm:w-auto dark:bg-onda-blue-500 dark:hover:bg-onda-blue-400"
                        >
                            <span>{{ t('hero.primaryCta') }}</span>
                            <ArrowRight class="size-4 rtl:rotate-180" />
                        </Link>

                        <a
                            href="#services"
                            class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white/80 px-6 text-sm font-semibold text-slate-700 shadow-sm backdrop-blur-sm transition-all hover:bg-slate-50 hover:text-onda-blue-600 sm:w-auto dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-200 dark:hover:bg-slate-800"
                        >
                            <span>{{ t('hero.secondaryCta') }}</span>
                        </a>
                    </div>

                    <!-- Trust Pillars -->
                    <div
                        class="flex flex-wrap items-center justify-center gap-6 pt-4 text-xs font-medium text-slate-500 lg:justify-start dark:text-slate-400"
                    >
                        <span class="flex items-center gap-1.5">
                            <CheckCircle2 class="size-4 text-onda-teal-500" />
                            {{ t('hero.trust1') }}
                        </span>
                        <span class="flex items-center gap-1.5">
                            <CheckCircle2 class="size-4 text-onda-teal-500" />
                            {{ t('hero.trust2') }}
                        </span>
                        <span class="flex items-center gap-1.5">
                            <CheckCircle2 class="size-4 text-onda-teal-500" />
                            {{ t('hero.trust3') }}
                        </span>
                    </div>
                </div>

                <!-- Right Column: Interactive Digital Certificate Card -->
                <div class="lg:col-span-5">
                    <div
                        class="relative mx-auto max-w-md rounded-3xl border border-slate-200/80 bg-white/80 p-6 shadow-2xl backdrop-blur-xl dark:border-slate-800/90 dark:bg-slate-900/80"
                    >
                        <!-- Header of Certificate -->
                        <div
                            class="flex items-center justify-between border-b border-slate-100 pb-4 dark:border-slate-800"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-11 items-center justify-center rounded-2xl bg-gradient-to-tr from-onda-blue-700 to-onda-blue-500 text-xs font-bold tracking-wider text-white shadow-md shadow-onda-blue-600/20"
                                >
                                    ONDA
                                </div>
                                <div>
                                    <div
                                        class="text-xs font-bold text-slate-900 dark:text-white"
                                    >
                                        {{ t('cert.title') }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        {{ t('cert.sub') }}
                                    </div>
                                </div>
                            </div>
                            <span
                                class="inline-flex items-center gap-1 rounded-full border border-onda-teal-200 bg-onda-teal-50 px-2.5 py-0.5 text-[11px] font-semibold text-onda-teal-800 dark:border-onda-teal-800/50 dark:bg-onda-teal-950 dark:text-onda-teal-300"
                            >
                                <Check class="size-3 text-onda-teal-600" />
                                {{ t('cert.authenticated') }}
                            </span>
                        </div>

                        <!-- Certificate Body Details -->
                        <div class="mt-4 space-y-3 font-mono text-xs">
                            <div
                                class="rounded-xl border border-slate-100 bg-slate-50 p-3.5 dark:border-slate-800/80 dark:bg-slate-800/50"
                            >
                                <div
                                    class="text-[10px] tracking-wider text-slate-400 uppercase"
                                >
                                    {{ t('cert.ref') }}
                                </div>
                                <div
                                    class="mt-0.5 font-bold text-onda-blue-600 dark:text-onda-blue-400"
                                >
                                    DZ-ONDA-2026-8942-X
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2.5">
                                <div
                                    class="rounded-xl border border-slate-100 bg-slate-50 p-3 dark:border-slate-800/80 dark:bg-slate-800/50"
                                >
                                    <div
                                        class="text-[10px] tracking-wider text-slate-400 uppercase"
                                    >
                                        {{ t('cert.category') }}
                                    </div>
                                    <div
                                        class="mt-0.5 truncate font-semibold text-slate-700 dark:text-slate-200"
                                    >
                                        {{ t('cert.categoryVal') }}
                                    </div>
                                </div>
                                <div
                                    class="rounded-xl border border-slate-100 bg-slate-50 p-3 dark:border-slate-800/80 dark:bg-slate-800/50"
                                >
                                    <div
                                        class="text-[10px] tracking-wider text-slate-400 uppercase"
                                    >
                                        {{ t('cert.status') }}
                                    </div>
                                    <div
                                        class="mt-0.5 truncate font-semibold text-onda-teal-600 dark:text-onda-teal-400"
                                    >
                                        {{ t('cert.statusVal') }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Verification Strip with QR Code Badge -->
                        <div
                            class="mt-5 rounded-2xl bg-gradient-to-r from-onda-blue-900 to-onda-teal-900 p-4 text-white shadow-lg"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white/15 backdrop-blur-sm"
                                >
                                    <QrCode class="size-6 text-onda-teal-300" />
                                </div>
                                <div>
                                    <div class="text-xs font-bold text-white">
                                        {{ t('cert.instantVerify') }}
                                    </div>
                                    <div
                                        class="mt-0.5 text-[11px] leading-snug text-slate-200"
                                    >
                                        {{ t('cert.instantVerifyDesc') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================================================= -->
        <!-- 2. LIVE FIGURES / KEY STATS STRIP -->
        <!-- ========================================================================= -->
        <section
            class="relative border-y border-slate-800 bg-slate-900 text-white"
        >
            <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                <div class="grid grid-cols-2 gap-8 text-center lg:grid-cols-4">
                    <div class="space-y-1">
                        <div
                            class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl"
                        >
                            {{ t('stats.works') }}
                        </div>
                        <div class="text-xs font-medium text-onda-blue-200">
                            {{ t('stats.worksLabel') }}
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div
                            class="text-3xl font-extrabold tracking-tight text-onda-teal-300 sm:text-4xl"
                        >
                            {{ t('stats.members') }}
                        </div>
                        <div class="text-xs font-medium text-onda-blue-200">
                            {{ t('stats.membersLabel') }}
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div
                            class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl"
                        >
                            {{ t('stats.agencies') }}
                        </div>
                        <div class="text-xs font-medium text-onda-blue-200">
                            {{ t('stats.agenciesLabel') }}
                        </div>
                    </div>

                    <div class="space-y-1">
                        <div
                            class="text-3xl font-extrabold tracking-tight text-onda-teal-300 sm:text-4xl"
                        >
                            {{ t('stats.protection') }}
                        </div>
                        <div class="text-xs font-medium text-onda-blue-200">
                            {{ t('stats.protectionLabel') }}
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================================================= -->
        <!-- 3. CORE SERVICES 4-CARD GRID -->
        <!-- ========================================================================= -->
        <section
            id="services"
            class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8"
        >
            <div class="mb-14 space-y-3 text-center">
                <div
                    class="inline-flex items-center gap-2 rounded-full border border-onda-blue-200 bg-onda-blue-50 px-3 py-1 text-xs font-semibold text-onda-blue-700 dark:border-onda-blue-800 dark:bg-onda-blue-950 dark:text-onda-blue-300"
                >
                    {{ t('services.heading') }}
                </div>
                <h2
                    class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white"
                >
                    {{ t('services.heading') }}
                </h2>
                <p
                    class="mx-auto max-w-2xl text-sm text-slate-600 sm:text-base dark:text-slate-400"
                >
                    {{ t('services.subheading') }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <!-- Card 1: Dépôt d'œuvre -->
                <div
                    class="group relative flex flex-col justify-between rounded-3xl border border-border bg-card p-7 shadow-sm transition-all duration-300 hover:-translate-y-1.5 hover:border-onda-blue-400 hover:shadow-xl dark:hover:border-onda-blue-500/50"
                >
                    <div>
                        <div class="flex items-center justify-between">
                            <div
                                class="flex size-12 items-center justify-center rounded-2xl bg-onda-blue-50 text-onda-blue-600 transition-colors group-hover:bg-onda-blue-600 group-hover:text-white dark:bg-onda-blue-950 dark:text-onda-blue-300"
                            >
                                <ShieldCheck class="size-6" />
                            </div>
                            <span
                                class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                            >
                                {{ t('services.card1.badge') }}
                            </span>
                        </div>
                        <h3
                            class="mt-6 text-lg font-bold text-slate-900 dark:text-white"
                        >
                            {{ t('services.card1.title') }}
                        </h3>
                        <p
                            class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('services.card1.desc') }}
                        </p>
                    </div>

                    <Link
                        :href="register()"
                        class="mt-6 flex items-center gap-1.5 border-t border-border pt-4 text-xs font-semibold text-onda-blue-600 group-hover:text-onda-blue-700 dark:text-onda-blue-400"
                    >
                        <span>{{ t('services.access') }}</span>
                        <ArrowRight
                            class="size-3.5 transition-transform group-hover:translate-x-1 rtl:group-hover:-translate-x-1"
                        />
                    </Link>
                </div>

                <!-- Card 2: E-Portail des Auteurs -->
                <div
                    class="group relative flex flex-col justify-between rounded-3xl border border-border bg-card p-7 shadow-sm transition-all duration-300 hover:-translate-y-1.5 hover:border-onda-teal-400 hover:shadow-xl dark:hover:border-onda-teal-500/50"
                >
                    <div>
                        <div class="flex items-center justify-between">
                            <div
                                class="flex size-12 items-center justify-center rounded-2xl bg-onda-teal-50 text-onda-teal-600 transition-colors group-hover:bg-onda-teal-600 group-hover:text-white dark:bg-onda-teal-950 dark:text-onda-teal-300"
                            >
                                <Sparkles class="size-6" />
                            </div>
                            <span
                                class="rounded-full bg-onda-teal-50 px-2.5 py-0.5 text-xs font-semibold text-onda-teal-700 dark:bg-onda-teal-950 dark:text-onda-teal-300"
                            >
                                {{ t('services.card2.badge') }}
                            </span>
                        </div>
                        <h3
                            class="mt-6 text-lg font-bold text-slate-900 dark:text-white"
                        >
                            {{ t('services.card2.title') }}
                        </h3>
                        <p
                            class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('services.card2.desc') }}
                        </p>
                    </div>

                    <Link
                        :href="login()"
                        class="mt-6 flex items-center gap-1.5 border-t border-border pt-4 text-xs font-semibold text-onda-teal-600 group-hover:text-onda-teal-700 dark:text-onda-teal-400"
                    >
                        <span>{{ t('services.access') }}</span>
                        <ArrowRight
                            class="size-3.5 transition-transform group-hover:translate-x-1 rtl:group-hover:-translate-x-1"
                        />
                    </Link>
                </div>

                <!-- Card 3: Paiement des Redevances -->
                <div
                    class="group relative flex flex-col justify-between rounded-3xl border border-border bg-card p-7 shadow-sm transition-all duration-300 hover:-translate-y-1.5 hover:border-onda-blue-400 hover:shadow-xl dark:hover:border-onda-blue-500/50"
                >
                    <div>
                        <div class="flex items-center justify-between">
                            <div
                                class="flex size-12 items-center justify-center rounded-2xl bg-onda-blue-50 text-onda-blue-600 transition-colors group-hover:bg-onda-blue-600 group-hover:text-white dark:bg-onda-blue-950 dark:text-onda-blue-300"
                            >
                                <Award class="size-6" />
                            </div>
                            <span
                                class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                            >
                                {{ t('services.card3.badge') }}
                            </span>
                        </div>
                        <h3
                            class="mt-6 text-lg font-bold text-slate-900 dark:text-white"
                        >
                            {{ t('services.card3.title') }}
                        </h3>
                        <p
                            class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('services.card3.desc') }}
                        </p>
                    </div>

                    <a
                        href="#contact"
                        class="mt-6 flex items-center gap-1.5 border-t border-border pt-4 text-xs font-semibold text-onda-blue-600 group-hover:text-onda-blue-700 dark:text-onda-blue-400"
                    >
                        <span>{{ t('services.access') }}</span>
                        <ArrowRight
                            class="size-3.5 transition-transform group-hover:translate-x-1 rtl:group-hover:-translate-x-1"
                        />
                    </a>
                </div>

                <!-- Card 4: Répertoire National -->
                <div
                    class="group relative flex flex-col justify-between rounded-3xl border border-border bg-card p-7 shadow-sm transition-all duration-300 hover:-translate-y-1.5 hover:border-onda-teal-400 hover:shadow-xl dark:hover:border-onda-teal-500/50"
                >
                    <div>
                        <div class="flex items-center justify-between">
                            <div
                                class="flex size-12 items-center justify-center rounded-2xl bg-onda-teal-50 text-onda-teal-600 transition-colors group-hover:bg-onda-teal-600 group-hover:text-white dark:bg-onda-teal-950 dark:text-onda-teal-300"
                            >
                                <Search class="size-6" />
                            </div>
                            <span
                                class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                            >
                                {{ t('services.card4.badge') }}
                            </span>
                        </div>
                        <h3
                            class="mt-6 text-lg font-bold text-slate-900 dark:text-white"
                        >
                            {{ t('services.card4.title') }}
                        </h3>
                        <p
                            class="mt-2 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('services.card4.desc') }}
                        </p>
                    </div>

                    <a
                        href="#repertoire"
                        class="mt-6 flex items-center gap-1.5 border-t border-border pt-4 text-xs font-semibold text-onda-teal-600 group-hover:text-onda-teal-700 dark:text-onda-teal-400"
                    >
                        <span>{{ t('services.access') }}</span>
                        <ArrowRight
                            class="size-3.5 transition-transform group-hover:translate-x-1 rtl:group-hover:-translate-x-1"
                        />
                    </a>
                </div>
            </div>
        </section>

        <!-- ========================================================================= -->
        <!-- 4. CULTURAL REPERTOIRE SEARCH ENGINE -->
        <!-- ========================================================================= -->
        <section
            id="repertoire"
            class="border-t border-border bg-slate-50/50 py-20 dark:bg-slate-950/40"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mb-10 space-y-3 text-center">
                    <h2
                        class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white"
                    >
                        {{ t('repertoire.heading') }}
                    </h2>
                    <p
                        class="mx-auto max-w-2xl text-xs text-muted-foreground sm:text-sm"
                    >
                        {{ t('repertoire.subheading') }}
                    </p>
                </div>

                <!-- Search Card Container -->
                <div
                    class="mx-auto max-w-3xl rounded-3xl border border-border bg-card p-6 shadow-xl sm:p-8"
                >
                    <!-- Search Input -->
                    <div class="relative">
                        <Search
                            class="absolute start-4 top-1/2 size-5 -translate-y-1/2 text-muted-foreground"
                        />
                        <input
                            v-model="searchQuery"
                            type="text"
                            :placeholder="t('repertoire.searchPlaceholder')"
                            class="h-13 w-full rounded-2xl border border-input bg-background ps-12 pe-4 text-sm text-foreground transition-all placeholder:text-muted-foreground/70 focus:border-onda-blue-600 focus:ring-2 focus:ring-onda-blue-600/20 focus:outline-none"
                        />
                    </div>

                    <!-- Category Pills Filter -->
                    <div
                        class="mt-4 flex flex-wrap items-center gap-2 border-t border-border/60 pt-2"
                    >
                        <button
                            type="button"
                            @click="activeFilter = 'all'"
                            :class="
                                activeFilter === 'all'
                                    ? 'bg-onda-blue-600 font-semibold text-white shadow-sm'
                                    : 'bg-muted text-muted-foreground hover:text-foreground'
                            "
                            class="cursor-pointer rounded-xl px-3.5 py-1.5 text-xs transition-colors"
                        >
                            {{ t('repertoire.filterAll') }}
                        </button>
                        <button
                            type="button"
                            @click="activeFilter = 'music'"
                            :class="
                                activeFilter === 'music'
                                    ? 'bg-onda-blue-600 font-semibold text-white shadow-sm'
                                    : 'bg-muted text-muted-foreground hover:text-foreground'
                            "
                            class="cursor-pointer rounded-xl px-3.5 py-1.5 text-xs transition-colors"
                        >
                            {{ t('repertoire.filterMusic') }}
                        </button>
                        <button
                            type="button"
                            @click="activeFilter = 'literature'"
                            :class="
                                activeFilter === 'literature'
                                    ? 'bg-onda-blue-600 font-semibold text-white shadow-sm'
                                    : 'bg-muted text-muted-foreground hover:text-foreground'
                            "
                            class="cursor-pointer rounded-xl px-3.5 py-1.5 text-xs transition-colors"
                        >
                            {{ t('repertoire.filterLiterature') }}
                        </button>
                        <button
                            type="button"
                            @click="activeFilter = 'cinema'"
                            :class="
                                activeFilter === 'cinema'
                                    ? 'bg-onda-blue-600 font-semibold text-white shadow-sm'
                                    : 'bg-muted text-muted-foreground hover:text-foreground'
                            "
                            class="cursor-pointer rounded-xl px-3.5 py-1.5 text-xs transition-colors"
                        >
                            {{ t('repertoire.filterCinema') }}
                        </button>
                    </div>

                    <!-- Filtered Sample Results Preview -->
                    <div
                        class="mt-6 divide-y divide-border overflow-hidden rounded-2xl border border-border bg-background"
                    >
                        <div
                            v-for="work in filteredWorks"
                            :key="work.id"
                            class="flex flex-col justify-between gap-2 p-4 transition-colors hover:bg-muted/40 sm:flex-row sm:items-center"
                        >
                            <div class="space-y-0.5">
                                <div class="text-sm font-bold text-foreground">
                                    {{ work.title }}
                                </div>
                                <div
                                    class="flex items-center gap-2 text-xs text-muted-foreground"
                                >
                                    <span>{{ work.author }}</span>
                                    <span>•</span>
                                    <span
                                        class="font-mono text-[11px] text-onda-blue-600 dark:text-onda-blue-400"
                                        >{{ work.categoryName }}</span
                                    >
                                </div>
                            </div>

                            <div class="flex shrink-0 items-center gap-3">
                                <span
                                    class="rounded-lg bg-muted px-2.5 py-1 font-mono text-xs text-muted-foreground"
                                >
                                    {{ work.ref }}
                                </span>
                                <span
                                    class="rounded-md bg-onda-teal-50 px-2 py-0.5 text-[11px] font-semibold text-onda-teal-600 dark:bg-onda-teal-950 dark:text-onda-teal-300"
                                >
                                    {{ work.status }}
                                </span>
                            </div>
                        </div>

                        <div
                            v-if="filteredWorks.length === 0"
                            class="p-8 text-center text-xs text-muted-foreground"
                        >
                            Aucun résultat ne correspond à votre recherche dans
                            le répertoire national.
                        </div>
                    </div>

                    <div
                        class="mt-4 text-center text-[11px] text-muted-foreground"
                    >
                        {{ t('repertoire.resultsNotice') }}
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================================================= -->
        <!-- 5. NEWS & REGULATORY BULLETINS -->
        <!-- ========================================================================= -->
        <section
            id="actualites"
            class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8"
        >
            <div class="mb-14 space-y-3 text-center">
                <h2
                    class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white"
                >
                    {{ t('news.heading') }}
                </h2>
                <p class="mx-auto max-w-2xl text-sm text-muted-foreground">
                    {{ t('news.subheading') }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                <!-- News 1 -->
                <article
                    class="group flex flex-col justify-between rounded-3xl border border-border bg-card p-6 shadow-sm transition-all hover:border-onda-blue-400 hover:shadow-lg"
                >
                    <div>
                        <div
                            class="flex items-center justify-between text-xs text-muted-foreground"
                        >
                            <span
                                class="rounded-full bg-onda-teal-50 px-2.5 py-0.5 font-semibold text-onda-teal-700 dark:bg-onda-teal-950 dark:text-onda-teal-300"
                            >
                                {{ t('news.item1.category') }}
                            </span>
                            <span class="flex items-center gap-1">
                                <Calendar class="size-3.5" />
                                {{ t('news.item1.date') }}
                            </span>
                        </div>
                        <h3
                            class="mt-4 text-base leading-snug font-bold text-slate-900 transition-colors group-hover:text-onda-blue-600 dark:text-white dark:group-hover:text-onda-blue-400"
                        >
                            {{ t('news.item1.title') }}
                        </h3>
                        <p
                            class="mt-3 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('news.item1.desc') }}
                        </p>
                    </div>

                    <a
                        href="#actualites"
                        class="mt-6 flex items-center gap-1.5 border-t border-border pt-4 text-xs font-semibold text-onda-blue-600 hover:text-onda-blue-700 dark:text-onda-blue-400"
                    >
                        <span>{{ t('news.readMore') }}</span>
                        <ArrowUpRight class="size-3.5" />
                    </a>
                </article>

                <!-- News 2 -->
                <article
                    class="group flex flex-col justify-between rounded-3xl border border-border bg-card p-6 shadow-sm transition-all hover:border-onda-blue-400 hover:shadow-lg"
                >
                    <div>
                        <div
                            class="flex items-center justify-between text-xs text-muted-foreground"
                        >
                            <span
                                class="rounded-full bg-onda-blue-50 px-2.5 py-0.5 font-semibold text-onda-blue-700 dark:bg-onda-blue-950 dark:text-onda-blue-300"
                            >
                                {{ t('news.item2.category') }}
                            </span>
                            <span class="flex items-center gap-1">
                                <Calendar class="size-3.5" />
                                {{ t('news.item2.date') }}
                            </span>
                        </div>
                        <h3
                            class="mt-4 text-base leading-snug font-bold text-slate-900 transition-colors group-hover:text-onda-blue-600 dark:text-white dark:group-hover:text-onda-blue-400"
                        >
                            {{ t('news.item2.title') }}
                        </h3>
                        <p
                            class="mt-3 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('news.item2.desc') }}
                        </p>
                    </div>

                    <a
                        href="#actualites"
                        class="mt-6 flex items-center gap-1.5 border-t border-border pt-4 text-xs font-semibold text-onda-blue-600 hover:text-onda-blue-700 dark:text-onda-blue-400"
                    >
                        <span>{{ t('news.readMore') }}</span>
                        <ArrowUpRight class="size-3.5" />
                    </a>
                </article>

                <!-- News 3 -->
                <article
                    class="group flex flex-col justify-between rounded-3xl border border-border bg-card p-6 shadow-sm transition-all hover:border-onda-blue-400 hover:shadow-lg"
                >
                    <div>
                        <div
                            class="flex items-center justify-between text-xs text-muted-foreground"
                        >
                            <span
                                class="rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                            >
                                {{ t('news.item3.category') }}
                            </span>
                            <span class="flex items-center gap-1">
                                <Calendar class="size-3.5" />
                                {{ t('news.item3.date') }}
                            </span>
                        </div>
                        <h3
                            class="mt-4 text-base leading-snug font-bold text-slate-900 transition-colors group-hover:text-onda-blue-600 dark:text-white dark:group-hover:text-onda-blue-400"
                        >
                            {{ t('news.item3.title') }}
                        </h3>
                        <p
                            class="mt-3 text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ t('news.item3.desc') }}
                        </p>
                    </div>

                    <a
                        href="#actualites"
                        class="mt-6 flex items-center gap-1.5 border-t border-border pt-4 text-xs font-semibold text-onda-blue-600 hover:text-onda-blue-700 dark:text-onda-blue-400"
                    >
                        <span>{{ t('news.readMore') }}</span>
                        <ArrowUpRight class="size-3.5" />
                    </a>
                </article>
            </div>
        </section>

        <!-- ========================================================================= -->
        <!-- 6. WHAT YOU CAN DEPOSIT (4 DISCIPLINES) -->
        <!-- ========================================================================= -->
        <section class="border-t border-border bg-card py-20">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto mb-12 max-w-2xl space-y-3 text-center">
                    <h2
                        class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                    >
                        {{ t('deposit.heading') }}
                    </h2>
                    <p class="text-xs text-muted-foreground sm:text-sm">
                        {{ t('deposit.intro') }}
                    </p>
                </div>

                <div
                    class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div
                        v-for="category in categories"
                        :key="category.key"
                        class="flex flex-col items-center gap-3 rounded-2xl border border-border bg-background/50 p-7 text-center transition-colors hover:border-onda-blue-400"
                    >
                        <div
                            class="flex size-14 items-center justify-center rounded-2xl bg-onda-blue-50 text-onda-blue-600 dark:bg-onda-blue-950 dark:text-onda-blue-300"
                        >
                            <component :is="category.Icon" class="size-7" />
                        </div>
                        <h3 class="mt-2 text-sm font-bold text-foreground">
                            {{ t(category.key) }}
                        </h3>
                        <p
                            class="text-xs leading-relaxed text-muted-foreground"
                        >
                            {{ category.desc }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ========================================================================= -->
        <!-- 7. 3-STEP PROCESS -->
        <!-- ========================================================================= -->
        <section class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
            <div class="mx-auto mb-16 max-w-2xl space-y-3 text-center">
                <h2
                    class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                >
                    {{ t('howItWorks.heading') }}
                </h2>
            </div>

            <ol class="grid grid-cols-1 gap-8 md:grid-cols-3">
                <li
                    v-for="step in steps"
                    :key="step.number"
                    class="relative flex flex-col items-center rounded-3xl border border-border bg-card p-6 text-center shadow-sm"
                >
                    <div
                        class="flex size-14 items-center justify-center rounded-2xl bg-onda-blue-600 font-mono text-lg font-bold text-white shadow-md shadow-onda-blue-600/30"
                    >
                        {{ step.number }}
                    </div>
                    <h3 class="mt-6 text-base font-bold text-foreground">
                        {{ t(step.titleKey) }}
                    </h3>
                    <p
                        class="mt-2 max-w-xs text-xs leading-relaxed text-muted-foreground"
                    >
                        {{ t(step.descriptionKey) }}
                    </p>
                </li>
            </ol>
        </section>

        <!-- ========================================================================= -->
        <!-- 8. SECURITY GUARANTEES & CLOSING ACTION -->
        <!-- ========================================================================= -->
        <section
            class="border-t border-border bg-slate-50/70 py-20 dark:bg-slate-900/40"
        >
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto mb-12 max-w-2xl space-y-3 text-center">
                    <h2
                        class="text-2xl font-bold tracking-tight text-foreground sm:text-3xl"
                    >
                        {{ t('guarantees.heading') }}
                    </h2>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="item in guarantees"
                        :key="item.key"
                        class="flex items-center gap-3.5 rounded-2xl border border-border bg-card p-5 text-xs text-foreground shadow-sm"
                    >
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-onda-teal-50 text-onda-teal-600 dark:bg-onda-teal-950 dark:text-onda-teal-300"
                        >
                            <component :is="item.icon" class="size-5" />
                        </div>
                        <span class="leading-relaxed font-medium">{{
                            t(item.key)
                        }}</span>
                    </div>
                </div>

                <!-- Final CTA Box -->
                <div
                    class="mt-16 rounded-3xl bg-gradient-to-r from-onda-blue-900 via-onda-blue-800 to-onda-teal-900 p-8 text-center text-white shadow-2xl sm:p-12"
                >
                    <h3 class="text-2xl font-bold tracking-tight sm:text-3xl">
                        {{ t('closing.heading') }}
                    </h3>
                    <p
                        class="mx-auto mt-3 max-w-xl text-xs leading-relaxed text-slate-200 sm:text-sm"
                    >
                        {{ t('closing.subtitle') }}
                    </p>
                    <div class="mt-8 flex justify-center">
                        <Link
                            :href="register()"
                            class="inline-flex h-12 cursor-pointer items-center justify-center gap-2 rounded-xl bg-onda-teal-500 px-8 text-sm font-bold text-white shadow-lg shadow-onda-teal-500/30 transition-all hover:bg-onda-teal-400 hover:shadow-onda-teal-500/50"
                        >
                            <span>{{ t('closing.cta') }}</span>
                            <ArrowRight class="size-4 rtl:rotate-180" />
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
