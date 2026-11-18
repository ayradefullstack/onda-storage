<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, ShieldCheck } from '@lucide/vue';
import { nextTick, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLoader from '@/components/AppLoader.vue';
import AuthStage from '@/components/auth/AuthStage.vue';
import LanguageSwitcher from '@/components/public/LanguageSwitcher.vue';
import ThemeToggle from '@/components/public/ThemeToggle.vue';
import { home } from '@/routes';

/**
 * Shell of every `auth/*` page (chosen by name in app.ts): top bar, the form
 * card, the brand stage and the trust line. Inertia keeps this layout mounted
 * between Login ↔ Register ↔ Forgot password, so the stage never remounts and
 * the browser's Back button simply walks the real history.
 */
const { t, locale } = useI18n();

defineProps<{
    title?: string;
    description?: string;
}>();

// After a client-side visit, land on the new page's heading (what a full page
// load would have announced). Not on the first load, to avoid a focus jump.
let navigated = false;
const offStart = router.on('start', () => (navigated = true));
const offNavigate = router.on('navigate', () => {
    if (!navigated) {
        return;
    }

    void nextTick(() => {
        document.querySelector<HTMLElement>('[data-auth-title]')?.focus({
            preventScroll: true,
        });
    });
});

onBeforeUnmount(() => {
    offStart();
    offNavigate();
});
</script>

<template>
    <AppLoader />

    <div
        class="auth-root auth-bg relative flex min-h-screen flex-col overflow-x-clip bg-background text-foreground"
    >
        <a href="#auth-card" class="auth-skip">{{ t('authUi.skip') }}</a>

        <header
            class="mx-auto flex w-full max-w-[1440px] items-center justify-between gap-3 px-[clamp(1rem,4vw,2.5rem)] py-4"
        >
            <Link
                :href="home({ locale })"
                class="inline-flex h-11 items-center gap-2 rounded-full border border-border bg-surface px-3.5 text-sm font-medium text-foreground transition-[border-color,transform] duration-200 hover:-translate-y-px hover:border-input-border focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:h-10 sm:px-4"
            >
                <ArrowLeft class="size-4 rtl:rotate-180" aria-hidden="true" />
                <span class="max-sm:sr-only">{{ t('auth.backHome') }}</span>
            </Link>

            <div class="flex items-center gap-2">
                <LanguageSwitcher />
                <ThemeToggle />
            </div>
        </header>

        <main
            class="mx-auto grid w-full max-w-[1400px] flex-1 grid-cols-1 items-start gap-[clamp(1.5rem,4vw,4rem)] px-[clamp(1rem,4vw,2.5rem)] pt-1 pb-12 min-[900px]:grid-cols-2"
        >
            <!-- Form first in the DOM: it is what a screen reader reaches first. -->
            <div class="flex flex-col items-center gap-[18px]">
                <div id="auth-card" class="auth-card" tabindex="-1">
                    <div v-if="title" class="mb-8 space-y-2">
                        <h1
                            data-auth-title
                            tabindex="-1"
                            class="text-2xl font-bold tracking-tight text-foreground outline-none sm:text-3xl"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>

                    <slot />
                </div>

                <p
                    class="inline-flex items-center gap-2 text-[13px] text-muted-foreground"
                >
                    <ShieldCheck
                        class="size-3.5 text-accent-text"
                        aria-hidden="true"
                    />
                    <span>{{ t('hero.trust3') }}</span>
                </p>
            </div>

            <AuthStage class="max-[899px]:order-first" />
        </main>
    </div>
</template>
