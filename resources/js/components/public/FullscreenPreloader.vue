<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useGlobalLoader } from '@/composables/useGlobalLoader';

const props = withDefaults(
    defineProps<{
        minDuration?: number; // Initial mount display time in ms (e.g. 600ms)
    }>(),
    {
        minDuration: 600,
    },
);

const { t } = useI18n();
const {
    isLoading: isRequestLoading,
    loadingMessage: requestMessage,
    progress: requestProgress,
} = useGlobalLoader();

const isMounted = ref(false);
const isInitialMount = ref(true);
const initialProgress = ref(0);
let animationFrameId: number | null = null;
let startTime: number | null = null;

const animateInitialProgress = (timestamp: number) => {
    if (!startTime) {
        startTime = timestamp;
    }

    const elapsed = timestamp - startTime;
    const rawProgress = Math.min((elapsed / props.minDuration) * 100, 100);

    // Ease-out cubic
    const tRatio = rawProgress / 100;
    const eased = 1 - Math.pow(1 - tRatio, 3);
    initialProgress.value = Math.round(eased * 100);

    if (rawProgress < 100) {
        animationFrameId = requestAnimationFrame(animateInitialProgress);
    } else {
        setTimeout(() => {
            isInitialMount.value = false;

            if (
                !isRequestLoading.value &&
                typeof document !== 'undefined' &&
                document.body
            ) {
                document.body.style.overflow = '';
            }
        }, 120);
    }
};

// Combined visibility and progress
const isVisible = computed(
    () => isInitialMount.value || isRequestLoading.value,
);

const displayProgress = computed(() => {
    if (isRequestLoading.value) {
        return requestProgress.value;
    }

    return initialProgress.value;
});

const displayMessage = computed(() => {
    if (requestMessage.value) {
        return requestMessage.value;
    }

    if (isRequestLoading.value) {
        return t('common.processingRequest');
    }

    return t('common.loading');
});

onMounted(() => {
    isMounted.value = true;

    if (typeof document !== 'undefined' && document.body) {
        document.body.style.overflow = 'hidden';
    }

    animationFrameId = requestAnimationFrame(animateInitialProgress);

    setTimeout(() => {
        if (isInitialMount.value) {
            initialProgress.value = 100;
            isInitialMount.value = false;

            if (
                !isRequestLoading.value &&
                typeof document !== 'undefined' &&
                document.body
            ) {
                document.body.style.overflow = '';
            }
        }
    }, 1200);
});

onUnmounted(() => {
    if (animationFrameId) {
        cancelAnimationFrame(animationFrameId);
    }

    if (typeof document !== 'undefined' && document.body) {
        document.body.style.overflow = '';
    }
});
</script>

<template>
    <teleport v-if="isMounted" to="body">
        <transition
            enter-active-class="transition duration-250 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-400 ease-in-out"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-105 pointer-events-none"
        >
            <div
                v-if="isVisible"
                class="fixed inset-0 z-[99999] flex flex-col items-center justify-center overflow-hidden bg-[#070b14]/95 backdrop-blur-xl select-none"
                role="status"
                aria-live="polite"
                aria-label="Chargement de la page"
            >
                <!-- AMBIENT BACKGROUND GLOWS -->
                <div
                    class="pointer-events-none absolute -top-32 -left-32 size-96 animate-pulse rounded-full bg-onda-blue-600/20 blur-[120px]"
                />
                <div
                    class="pointer-events-none absolute -right-32 -bottom-32 size-96 animate-pulse rounded-full bg-onda-teal-500/20 blur-[120px]"
                />

                <!-- Subtle Radial Mesh Background -->
                <div
                    class="pointer-events-none absolute inset-0 bg-[radial-gradient(#1e293b_1px,transparent_1px)] [background-size:24px_24px] opacity-30"
                />

                <!-- CENTER CONTAINER -->
                <div
                    class="relative z-10 flex flex-col items-center justify-center px-6 text-center"
                >
                    <!-- DUAL ORBITAL SPINNER & LOGO CONTAINER -->
                    <div
                        class="relative mb-8 flex size-36 items-center justify-center sm:size-44"
                    >
                        <!-- Outer Ambient Pulse Ring -->
                        <div
                            class="absolute inset-0 animate-pulse rounded-full bg-gradient-to-tr from-onda-blue-600/30 to-onda-teal-400/30 blur-2xl"
                        />

                        <!-- Outer Rotating Conic Spinner -->
                        <svg
                            class="absolute inset-0 size-full animate-spin [animation-duration:2.5s]"
                            viewBox="0 0 160 160"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <circle
                                cx="80"
                                cy="80"
                                r="74"
                                stroke="rgba(255, 255, 255, 0.08)"
                                stroke-width="3"
                            />
                            <circle
                                cx="80"
                                cy="80"
                                r="74"
                                stroke="url(#onda-spinner-gradient)"
                                stroke-width="3.5"
                                stroke-linecap="round"
                                stroke-dasharray="140 320"
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

                        <!-- Center Glassmorphic Logo Shield -->
                        <div
                            class="relative flex size-24 items-center justify-center rounded-3xl border border-white/15 bg-gradient-to-br from-slate-900/90 via-slate-950/95 to-slate-900/90 p-4 shadow-2xl backdrop-blur-xl sm:size-28"
                        >
                            <!-- High Resolution ONDA Logo with Drop Shadow -->
                            <img
                                src="/assets/logos/logo.png"
                                alt="ONDA Logo"
                                class="h-12 w-auto object-contain drop-shadow-[0_8px_16px_rgba(27,102,157,0.5)] transition-transform duration-500 hover:scale-105 sm:h-14"
                            />

                            <!-- Shimmer light reflection effect -->
                            <div
                                class="pointer-events-none absolute inset-0 overflow-hidden rounded-3xl"
                            >
                                <div
                                    class="absolute inset-0 -translate-x-full animate-[shimmer_2s_infinite] bg-gradient-to-r from-transparent via-white/10 to-transparent"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- INSTITUTIONAL BILINGUAL BRANDING -->
                    <div class="mb-6 space-y-1.5">
                        <div
                            class="inline-flex items-center gap-2 rounded-full border border-onda-teal-500/30 bg-onda-teal-500/10 px-3.5 py-1 text-[11px] font-semibold text-onda-teal-300 backdrop-blur-md"
                        >
                            <span
                                class="size-1.5 animate-ping rounded-full bg-onda-teal-400"
                            />
                            <span>{{ t('common.portalTitle') }}</span>
                        </div>

                        <h2
                            class="text-base font-bold tracking-wide text-white sm:text-lg"
                        >
                            الديوان الوطني لحقوق المؤلف والحقوق المجاورة
                        </h2>
                        <p
                            class="text-xs font-medium text-slate-400 sm:text-sm"
                        >
                            Office National des Droits d'Auteur et des Droits
                            Voisins
                        </p>
                    </div>

                    <!-- LUMINOUS PROGRESS BAR & PERCENTAGE -->
                    <div class="w-64 space-y-2.5 sm:w-72">
                        <!-- Progress Track -->
                        <div
                            class="relative h-1.5 w-full overflow-hidden rounded-full border border-slate-700/50 bg-slate-800/80 shadow-inner"
                        >
                            <div
                                class="h-full rounded-full bg-gradient-to-r from-onda-blue-500 via-sky-400 to-onda-teal-400 shadow-[0_0_12px_rgba(28,153,118,0.8)] transition-all duration-150 ease-out"
                                :style="{ width: `${displayProgress}%` }"
                            />
                        </div>

                        <!-- Status text and Percentage Counter -->
                        <div
                            class="flex items-center justify-between font-mono text-[11px] font-medium text-slate-400"
                        >
                            <span
                                class="flex items-center gap-1.5 text-slate-400"
                            >
                                <span
                                    class="size-1.5 animate-pulse rounded-full bg-onda-blue-400"
                                />
                                <span>{{ displayMessage }}</span>
                            </span>
                            <span class="font-bold text-white"
                                >{{ displayProgress }}%</span
                            >
                        </div>
                    </div>
                </div>

                <!-- BOTTOM SOVEREIGN WATERMARK FOOTNOTE -->
                <div
                    class="absolute bottom-6 flex items-center gap-2 text-[10px] font-medium tracking-wider text-slate-500"
                >
                    <span>الجمهورية الجزائرية الديمقراطية الشعبية</span>
                    <span>•</span>
                    <span>ONDA Algérie</span>
                </div>
            </div>
        </transition>
    </teleport>
</template>

<style scoped>
@keyframes shimmer {
    100% {
        transform: translateX(100%);
    }
}
</style>
