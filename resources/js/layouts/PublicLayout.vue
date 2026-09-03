<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, Phone, Sparkles, Mail, MapPin, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FullscreenPreloader from '@/components/public/FullscreenPreloader.vue';
import LanguageSwitcher from '@/components/public/LanguageSwitcher.vue';
import OrgLogo from '@/components/public/OrgLogo.vue';
import ThemeToggle from '@/components/public/ThemeToggle.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { dashboard, home, login, logout, register } from '@/routes';

const { t, locale } = useI18n();
const page = usePage();
const mobileMenuOpen = ref(false);
</script>

<template>
    <!-- Fullscreen ONDA Logo & Orbital Spinner Loading Screen -->
    <FullscreenPreloader :min-duration="700" />

    <a
        href="#main-content"
        class="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-onda-blue-600 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white"
    >
        {{ t('nav.skipToContent') }}
    </a>

    <div
        class="flex min-h-screen flex-col bg-background text-foreground selection:bg-onda-blue-600 selection:text-white"
    >
        <!-- 1. TOP INSTITUTIONAL ANNOUNCEMENT BAR -->
        <aside
            class="relative z-50 border-b border-onda-blue-900/30 bg-gradient-to-r from-onda-blue-900 via-onda-blue-800 to-onda-teal-900 px-4 py-2 text-xs text-white sm:px-6 lg:px-8"
        >
            <div
                class="mx-auto flex max-w-7xl items-center justify-between gap-4"
            >
                <!-- Announcement Notice -->
                <div class="flex items-center gap-2 truncate overflow-hidden">
                    <span
                        class="inline-flex shrink-0 items-center rounded-full border border-onda-teal-400/30 bg-onda-teal-500/25 px-2.5 py-0.5 text-[11px] font-semibold text-onda-teal-200"
                    >
                        {{ t('announcement.badge') }}
                    </span>
                    <span class="truncate text-xs text-slate-200">
                        {{ t('announcement.text') }}
                    </span>
                </div>

                <!-- Right Utility Bar: Hotline & Quick Lang -->
                <div class="hidden shrink-0 items-center gap-6 md:flex">
                    <a
                        href="tel:+21321741234"
                        class="flex items-center gap-1.5 text-xs text-onda-blue-100 transition-colors hover:text-white"
                        title="Assistance Téléphonique"
                    >
                        <Phone class="size-3.5 text-onda-teal-400" />
                        <span>{{ t('announcement.hotline') }}</span>
                    </a>
                </div>
            </div>
        </aside>

        <!-- 2. STICKY MAIN NAVBAR -->
        <header
            class="sticky top-0 z-40 border-b border-border/80 bg-background/85 backdrop-blur-md transition-colors"
        >
            <div
                class="mx-auto flex h-18 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8"
            >
                <!-- Brand Logo -->
                <Link
                    :href="home({ locale })"
                    class="group flex items-center gap-3 transition-transform duration-200 hover:scale-[1.01]"
                >
                    <OrgLogo
                        class="h-9 w-auto object-contain drop-shadow-sm sm:h-11"
                    />
                </Link>

                <!-- Navigation Links -->
                <nav
                    class="hidden items-center gap-7 lg:flex"
                    :aria-label="t('nav.menu')"
                >
                    <Link
                        :href="home({ locale })"
                        class="text-sm font-semibold text-foreground transition-colors hover:text-onda-blue-600 dark:hover:text-onda-blue-400"
                    >
                        {{ t('nav.home') }}
                    </Link>

                    <a
                        href="#services"
                        class="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground hover:text-onda-blue-600 dark:hover:text-onda-blue-400"
                    >
                        {{ t('nav.services') }}
                    </a>

                    <a
                        href="#repertoire"
                        class="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground hover:text-onda-blue-600 dark:hover:text-onda-blue-400"
                    >
                        {{ t('nav.repertoire') }}
                    </a>

                    <a
                        href="#actualites"
                        class="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground hover:text-onda-blue-600 dark:hover:text-onda-blue-400"
                    >
                        {{ t('news.heading') }}
                    </a>

                    <a
                        href="#contact"
                        class="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground hover:text-onda-blue-600 dark:hover:text-onda-blue-400"
                    >
                        {{ t('nav.contact') }}
                    </a>
                </nav>

                <!-- Right Action Center -->
                <div class="hidden items-center gap-3 md:flex">
                    <LanguageSwitcher />
                    <ThemeToggle />

                    <div class="mx-1 h-5 w-px bg-border" />

                    <!-- User Account / Auth Buttons -->
                    <template v-if="page.props.auth?.user">
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                class="inline-flex items-center gap-2 rounded-xl border border-border bg-card px-3.5 py-2 text-sm font-semibold transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :aria-label="t('nav.userMenu')"
                            >
                                <span
                                    class="size-2 rounded-full bg-onda-teal-500"
                                />
                                <span>{{ page.props.auth.user.name }}</span>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-48">
                                <DropdownMenuItem as-child>
                                    <Link :href="dashboard()">
                                        {{ t('nav.dashboard') }}
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem as-child>
                                    <Link
                                        :href="logout()"
                                        method="post"
                                        as="button"
                                        class="w-full text-start text-destructive"
                                    >
                                        {{ t('nav.signOut') }}
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                    <template v-else>
                        <Link
                            :href="login()"
                            class="rounded-xl px-3.5 py-2 text-sm font-semibold text-foreground transition-colors hover:bg-accent hover:text-onda-blue-600 dark:hover:text-onda-blue-400"
                        >
                            {{ t('nav.signIn') }}
                        </Link>

                        <!-- E-Portail CTA Button (ONDA Teal) -->
                        <Link
                            :href="register()"
                            class="inline-flex items-center gap-2 rounded-xl bg-onda-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-md shadow-onda-teal-600/25 transition-all duration-200 hover:bg-onda-teal-700 hover:shadow-onda-teal-600/40 dark:bg-onda-teal-500 dark:hover:bg-onda-teal-400"
                        >
                            <Sparkles class="size-4 text-onda-teal-100" />
                            <span>{{ t('nav.eportal') }}</span>
                        </Link>
                    </template>
                </div>

                <!-- Mobile Menu Button -->
                <div class="flex items-center gap-2 lg:hidden">
                    <LanguageSwitcher />
                    <ThemeToggle />
                    <Sheet v-model:open="mobileMenuOpen">
                        <SheetTrigger
                            class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-border bg-card text-foreground hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="t('nav.openMenu')"
                        >
                            <Menu class="size-5" />
                        </SheetTrigger>
                        <SheetContent
                            side="end"
                            :close-label="t('nav.closeMenu')"
                            class="flex w-4/5 flex-col gap-6 sm:max-w-xs"
                        >
                            <SheetHeader>
                                <SheetTitle
                                    class="flex items-center gap-2 text-start"
                                >
                                    <OrgLogo class="h-8 w-auto" />
                                </SheetTitle>
                            </SheetHeader>

                            <nav
                                class="flex flex-col gap-1.5"
                                :aria-label="t('nav.menu')"
                            >
                                <SheetClose as-child>
                                    <Link
                                        :href="home({ locale })"
                                        class="rounded-xl px-3.5 py-2.5 text-sm font-semibold hover:bg-accent hover:text-onda-blue-600"
                                    >
                                        {{ t('nav.home') }}
                                    </Link>
                                </SheetClose>
                                <SheetClose as-child>
                                    <a
                                        href="#services"
                                        class="rounded-xl px-3.5 py-2.5 text-sm font-medium hover:bg-accent"
                                    >
                                        {{ t('nav.services') }}
                                    </a>
                                </SheetClose>
                                <SheetClose as-child>
                                    <a
                                        href="#repertoire"
                                        class="rounded-xl px-3.5 py-2.5 text-sm font-medium hover:bg-accent"
                                    >
                                        {{ t('nav.repertoire') }}
                                    </a>
                                </SheetClose>
                                <SheetClose as-child>
                                    <a
                                        href="#actualites"
                                        class="rounded-xl px-3.5 py-2.5 text-sm font-medium hover:bg-accent"
                                    >
                                        {{ t('news.heading') }}
                                    </a>
                                </SheetClose>
                                <SheetClose as-child>
                                    <a
                                        href="#contact"
                                        class="rounded-xl px-3.5 py-2.5 text-sm font-medium hover:bg-accent"
                                    >
                                        {{ t('nav.contact') }}
                                    </a>
                                </SheetClose>
                            </nav>

                            <div
                                class="mt-auto flex flex-col gap-2.5 border-t border-border pt-6"
                            >
                                <template v-if="page.props.auth?.user">
                                    <SheetClose as-child>
                                        <Link
                                            :href="dashboard()"
                                            class="rounded-xl border border-border px-3.5 py-2.5 text-center text-sm font-semibold hover:bg-accent"
                                        >
                                            {{ t('nav.dashboard') }}
                                        </Link>
                                    </SheetClose>
                                    <Link
                                        :href="logout()"
                                        method="post"
                                        as="button"
                                        class="rounded-xl px-3.5 py-2.5 text-center text-sm font-semibold text-destructive hover:bg-destructive/10"
                                    >
                                        {{ t('nav.signOut') }}
                                    </Link>
                                </template>
                                <template v-else>
                                    <SheetClose as-child>
                                        <Link
                                            :href="login()"
                                            class="rounded-xl border border-border px-3.5 py-2.5 text-center text-sm font-semibold hover:bg-accent"
                                        >
                                            {{ t('nav.signIn') }}
                                        </Link>
                                    </SheetClose>
                                    <SheetClose as-child>
                                        <Link
                                            :href="register()"
                                            class="rounded-xl bg-onda-teal-600 px-3.5 py-2.5 text-center text-sm font-semibold text-white hover:bg-onda-teal-700"
                                        >
                                            {{ t('nav.eportal') }}
                                        </Link>
                                    </SheetClose>
                                </template>
                            </div>
                        </SheetContent>
                    </Sheet>
                </div>
            </div>
        </header>

        <!-- 3. MAIN APP CONTENT -->
        <main id="main-content" class="flex-1">
            <slot />
        </main>

        <!-- 4. INSTITUTIONAL TRILINGUAL FOOTER -->
        <footer
            id="contact"
            class="border-t border-border bg-slate-900 text-slate-300 dark:bg-slate-950"
        >
            <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-12 lg:grid-cols-12">
                    <!-- Column 1: Institutional Overview -->
                    <div class="space-y-4 lg:col-span-4">
                        <div class="flex items-center gap-3">
                            <OrgLogo class="h-10 w-auto brightness-0 invert" />
                        </div>
                        <p
                            class="max-w-sm text-xs leading-relaxed text-slate-400"
                        >
                            {{ t('footer.aboutText') }}
                        </p>
                        <div
                            class="inline-flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-800/80 px-3 py-1.5 text-xs text-onda-teal-400"
                        >
                            <ShieldCheck class="size-4" />
                            <span>{{ t('hero.trust3') }}</span>
                        </div>
                    </div>

                    <!-- Column 2: Quick Links -->
                    <div class="space-y-3 lg:col-span-2">
                        <h4
                            class="text-xs font-bold tracking-wider text-white uppercase"
                        >
                            {{ t('footer.quickLinks') }}
                        </h4>
                        <ul class="space-y-2 text-xs">
                            <li>
                                <a
                                    href="#services"
                                    class="transition-colors hover:text-onda-blue-400"
                                >
                                    {{ t('nav.services') }}
                                </a>
                            </li>
                            <li>
                                <a
                                    href="#repertoire"
                                    class="transition-colors hover:text-onda-blue-400"
                                >
                                    {{ t('nav.repertoire') }}
                                </a>
                            </li>
                            <li>
                                <a
                                    href="#actualites"
                                    class="transition-colors hover:text-onda-blue-400"
                                >
                                    {{ t('news.heading') }}
                                </a>
                            </li>
                            <li>
                                <Link
                                    :href="register()"
                                    class="transition-colors hover:text-onda-teal-400"
                                >
                                    {{ t('nav.eportal') }}
                                </Link>
                            </li>
                        </ul>
                    </div>

                    <!-- Column 3: Legal & Regulatory -->
                    <div class="space-y-3 lg:col-span-3">
                        <h4
                            class="text-xs font-bold tracking-wider text-white uppercase"
                        >
                            {{ t('footer.legal') }}
                        </h4>
                        <ul class="space-y-2 text-xs text-slate-400">
                            <li>{{ t('footer.legal1') }}</li>
                            <li>{{ t('footer.legal2') }}</li>
                            <li>{{ t('footer.legal3') }}</li>
                        </ul>
                    </div>

                    <!-- Column 4: Contact & Agences -->
                    <div class="space-y-3 lg:col-span-3">
                        <h4
                            class="text-xs font-bold tracking-wider text-white uppercase"
                        >
                            {{ t('footer.contactUs') }}
                        </h4>
                        <div class="space-y-2.5 text-xs text-slate-400">
                            <div class="flex items-start gap-2">
                                <MapPin
                                    class="mt-0.5 size-4 shrink-0 text-onda-blue-400"
                                />
                                <span>{{ t('footer.address') }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <Phone
                                    class="size-4 shrink-0 text-onda-teal-400"
                                />
                                <a
                                    href="tel:+21321741234"
                                    class="font-mono transition-colors hover:text-white"
                                >
                                    +213 (0) 21 74 12 34
                                </a>
                            </div>
                            <div class="flex items-center gap-2">
                                <Mail
                                    class="size-4 shrink-0 text-onda-blue-400"
                                />
                                <a
                                    href="mailto:contact@onda.dz"
                                    class="font-mono transition-colors hover:text-white"
                                >
                                    contact@onda.dz
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Bottom Divider & Legal Mentions -->
                <div
                    class="mt-12 flex flex-col items-center justify-between gap-4 border-t border-slate-800 pt-8 text-xs text-slate-500 sm:flex-row"
                >
                    <p>{{ t('footer.storageNotice') }}</p>
                    <p class="font-mono text-[11px]">
                        {{
                            t('footer.copyright', {
                                year: new Date().getFullYear(),
                            })
                        }}
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
