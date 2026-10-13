<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { useGlobalLoader } from '@/composables/useGlobalLoader';
import { prefersReducedMotion } from '@/composables/useReducedMotion';

/**
 * THE loading screen of the app, on the landing page and in every layout.
 *
 * The design is the original landing-page preloader, restored as it was
 * (markup, sizes, gradients, ring and animations of
 * components/public/FullscreenPreloader.vue): the dotted grid and corner
 * glows, the logo tile inside its dashed ring and progress arc, the portal
 * badge, the bilingual institution lines, the gradient bar with its
 * percentage and status line, and the footer. Its colours are that
 * component's own values. The dark theme is the original; the light theme
 * is the same layout and hues on the light background token (the logo tile
 * stays dark, as designed).
 *
 * WHEN it shows is not decided here: `useGlobalLoader` is a state machine
 * that raises it only for a real navigation still running after 300 ms (see
 * that file for the exclusions). The ring arc and the bar show one number:
 * the visit's real progress, or a trickle that waits at 90 %.
 *
 * On mount it also retires the inline first-paint loader of app.blade.php.
 */
const { t } = useI18n();
const { phase, progress, real, retry } = useGlobalLoader();

// Served from public/; bound, not inlined, so the bundler leaves it alone.
const LOGO = '/assets/logos/logo.png';

const ARC_RADIUS = 74;
const ARC_LENGTH = 2 * Math.PI * ARC_RADIUS;
/** The original's fixed arc (140 of 465), used when no value is shown. */
const STATIC_ARC = 140;

const visible = computed(
    () =>
        phase.value === 'visible' ||
        phase.value === 'slow' ||
        phase.value === 'verySlow',
);

// Read when the overlay appears: both can change while the app is open.
const reduced = ref(false);
const dark = ref(false);

/**
 * The number on screen. Under reduced motion the trickle is not shown (it
 * is an animation, not information); real progress always is.
 */
const shown = computed<number | null>(() =>
    reduced.value && !real.value ? null : progress.value,
);

const arc = computed(() =>
    shown.value === null
        ? `${STATIC_ARC} ${ARC_LENGTH}`
        : `${(ARC_LENGTH * shown.value) / 100} ${ARC_LENGTH}`,
);

// The status line carries the phase: loading, then the 3 s and 15 s notes.
const message = computed(() => {
    if (phase.value === 'verySlow') {
        return t('loader.verySlow');
    }

    return phase.value === 'slow' ? t('loader.slow') : t('common.loading');
});

const retryButton = ref<{ $el: HTMLElement } | null>(null);

let lockedGutter = false;

/** Lock page scroll while visible, keeping the scrollbar's space. */
function lockScroll(lock: boolean): void {
    const root = document.documentElement;

    if (lock) {
        lockedGutter = window.innerWidth - root.clientWidth > 0;
        root.style.overflow = 'hidden';

        if (lockedGutter) {
            root.style.scrollbarGutter = 'stable';
        }

        return;
    }

    root.style.overflow = '';

    if (lockedGutter) {
        root.style.scrollbarGutter = '';
        lockedGutter = false;
    }
}

function markBusy(busy: boolean): void {
    document.querySelectorAll('main').forEach((main) => {
        if (busy) {
            main.setAttribute('aria-busy', 'true');
        } else {
            main.removeAttribute('aria-busy');
        }
    });
}

watch(visible, (now) => {
    if (now) {
        reduced.value = prefersReducedMotion();
        dark.value = document.documentElement.classList.contains('dark');
    }

    lockScroll(now);
    markBusy(now);
});

// Focus is taken only when there is something to act on.
watch(phase, async (now) => {
    if (now === 'verySlow') {
        await nextTick();
        retryButton.value?.$el.focus();
    }
});

onMounted(() => {
    // The first-paint loader has done its job: fade it out, then remove it.
    const initial = document.getElementById('app-initial-loader');

    if (initial !== null) {
        initial.setAttribute('data-leaving', 'true');
        setTimeout(() => initial.remove(), 260);
    }
});

onBeforeUnmount(() => {
    if (visible.value) {
        lockScroll(false);
        markBusy(false);
    }
});
</script>

<template>
    <Transition name="app-loader">
        <div
            v-if="visible"
            data-testid="app-loader"
            :data-phase="phase"
            :data-theme="dark ? 'dark' : 'light'"
            :data-motion="reduced ? 'reduced' : 'full'"
            class="app-loader fixed inset-0 z-[99999] flex flex-col items-center justify-center overflow-hidden bg-background/95 backdrop-blur-xl select-none dark:bg-[#070b14]/95"
            :class="reduced ? 'app-loader--static' : ''"
            role="status"
            aria-live="polite"
            :aria-label="t('loader.loading')"
        >
            <!-- AMBIENT BACKGROUND GLOWS -->
            <div
                class="pointer-events-none absolute -top-32 -left-32 size-96 animate-pulse rounded-full bg-onda-blue-600/10 blur-[120px] dark:bg-onda-blue-600/20"
            />
            <div
                class="pointer-events-none absolute -right-32 -bottom-32 size-96 animate-pulse rounded-full bg-onda-teal-500/10 blur-[120px] dark:bg-onda-teal-500/20"
            />

            <!-- Subtle Radial Mesh Background -->
            <div
                class="pointer-events-none absolute inset-0 bg-[radial-gradient(var(--color-muted-foreground)_1px,transparent_1px)] [background-size:24px_24px] opacity-15 dark:bg-[radial-gradient(#1e293b_1px,transparent_1px)] dark:opacity-30"
            />

            <!-- CENTER CONTAINER -->
            <div
                class="app-loader-mark relative z-10 flex flex-col items-center justify-center px-6 text-center"
            >
                <!-- DUAL ORBITAL SPINNER & LOGO CONTAINER -->
                <div
                    class="relative mb-8 flex size-36 items-center justify-center sm:size-44"
                >
                    <!-- Outer Ambient Pulse Ring -->
                    <div
                        class="absolute inset-0 animate-pulse rounded-full bg-gradient-to-tr from-onda-blue-600/30 to-onda-teal-400/30 blur-2xl"
                    />

                    <!-- Outer Rotating Progress Arc -->
                    <svg
                        class="absolute inset-0 size-full animate-spin text-foreground/10 [animation-duration:2.5s] dark:text-white/[0.08]"
                        viewBox="0 0 160 160"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                        data-testid="app-loader-ring"
                        :data-mode="real ? 'real' : 'trickle'"
                    >
                        <circle
                            cx="80"
                            cy="80"
                            r="74"
                            stroke="currentColor"
                            stroke-width="3"
                        />
                        <circle
                            class="app-loader-arc"
                            cx="80"
                            cy="80"
                            r="74"
                            stroke="url(#onda-spinner-gradient)"
                            stroke-width="3.5"
                            stroke-linecap="round"
                            :stroke-dasharray="arc"
                            data-testid="app-loader-arc"
                        />
                        <defs>
                            <linearGradient
                                id="onda-spinner-gradient"
                                x1="0"
                                y1="0"
                                x2="160"
                                y2="160"
                                gradientUnits="userSpaceOnUse"
                            >
                                <stop stop-color="#1B669D" />
                                <stop offset="0.5" stop-color="#38bdf8" />
                                <stop offset="1" stop-color="#1C9976" />
                            </linearGradient>
                        </defs>
                    </svg>

                    <!-- Counter-Rotating Dashed Ring -->
                    <svg
                        class="absolute inset-2 size-[calc(100%-16px)] animate-spin [animation-direction:reverse] [animation-duration:6s]"
                        viewBox="0 0 140 140"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                    >
                        <circle
                            cx="70"
                            cy="70"
                            r="64"
                            stroke="rgba(28, 153, 118, 0.4)"
                            stroke-width="1.5"
                            stroke-dasharray="6 10"
                        />
                    </svg>

                    <!-- Center Glassmorphic Logo Shield (dark in both themes) -->
                    <div
                        class="relative flex size-24 items-center justify-center rounded-3xl border border-white/15 bg-gradient-to-br from-slate-900/90 via-slate-950/95 to-slate-900/90 p-4 shadow-2xl backdrop-blur-xl sm:size-28"
                    >
                        <img
                            :src="LOGO"
                            alt=""
                            draggable="false"
                            class="h-12 w-auto object-contain drop-shadow-[0_8px_16px_rgba(27,102,157,0.5)] sm:h-14"
                        />

                        <!-- Shimmer light reflection effect -->
                        <div
                            class="pointer-events-none absolute inset-0 overflow-hidden rounded-3xl"
                        >
                            <div
                                class="app-loader-shimmer absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/10 to-transparent"
                            />
                        </div>
                    </div>
                </div>

                <!-- INSTITUTIONAL BILINGUAL BRANDING -->
                <div class="mb-6 space-y-1.5">
                    <div
                        class="inline-flex items-center gap-2 rounded-full border border-onda-teal-500/30 bg-onda-teal-500/10 px-3.5 py-1 text-[11px] font-semibold text-onda-teal-700 backdrop-blur-md dark:text-onda-teal-300"
                    >
                        <span
                            class="size-1.5 animate-ping rounded-full bg-onda-teal-600 dark:bg-onda-teal-400"
                        />
                        <span>{{ t('common.portalTitle') }}</span>
                    </div>

                    <!-- These two lines are the institution's name: always
                         both languages, whatever the locale. -->
                    <h2
                        class="text-base font-bold tracking-wide text-foreground sm:text-lg dark:text-white"
                        lang="ar"
                    >
                        الديوان الوطني لحقوق المؤلف والحقوق المجاورة
                    </h2>
                    <p
                        class="text-xs font-medium text-muted-foreground sm:text-sm dark:text-slate-400"
                        lang="fr"
                    >
                        Office National des Droits d'Auteur et des Droits
                        Voisins
                    </p>
                </div>

                <!-- LUMINOUS PROGRESS BAR & PERCENTAGE -->
                <div class="w-64 space-y-2.5 sm:w-72">
                    <!-- Progress Track -->
                    <div
                        class="relative h-1.5 w-full overflow-hidden rounded-full border border-border bg-muted shadow-inner dark:border-slate-700/50 dark:bg-slate-800/80"
                    >
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-onda-blue-500 via-sky-400 to-onda-teal-400 shadow-[0_0_12px_rgba(28,153,118,0.8)] transition-all duration-150 ease-out"
                            data-testid="app-loader-bar"
                            :style="{ width: `${shown ?? 0}%` }"
                        />
                    </div>

                    <!-- Status text and Percentage Counter -->
                    <div
                        class="flex items-center justify-between font-mono text-[11px] font-medium text-muted-foreground dark:text-slate-400"
                    >
                        <span class="flex items-center gap-1.5">
                            <span
                                class="size-1.5 animate-pulse rounded-full bg-onda-blue-600 dark:bg-onda-blue-400"
                            />
                            <Transition name="app-loader-text" mode="out-in">
                                <span
                                    :key="message"
                                    data-testid="app-loader-message"
                                    >{{ message }}</span
                                >
                            </Transition>
                        </span>
                        <span
                            v-if="shown !== null"
                            class="font-bold text-foreground tabular-nums dark:text-white"
                            data-testid="app-loader-percent"
                            >{{ shown }}%</span
                        >
                    </div>

                    <!-- After 15 s: never trap the user. -->
                    <Button
                        v-if="phase === 'verySlow'"
                        ref="retryButton"
                        type="button"
                        size="sm"
                        variant="outline"
                        class="mt-2"
                        data-testid="app-loader-retry"
                        @click="retry"
                    >
                        {{ t('loader.retry') }}
                    </Button>
                </div>
            </div>

            <!-- BOTTOM SOVEREIGN WATERMARK FOOTNOTE -->
            <div
                class="absolute bottom-6 flex items-center gap-2 text-[10px] font-medium tracking-wider text-muted-foreground dark:text-slate-500"
            >
                <span>الجمهورية الجزائرية الديمقراطية الشعبية</span>
                <span>•</span>
                <span>ONDA Algérie</span>
            </div>
        </div>
    </Transition>
</template>
