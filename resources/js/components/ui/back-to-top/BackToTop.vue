<script setup lang="ts">
import { ArrowUp } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { prefersReducedMotion } from '@/composables/useReducedMotion';
import { useScrolled } from '@/composables/useScrolled';

/**
 * A floating "back to top" button for long pages. It appears after one
 * viewport of scroll and returns to the top smoothly (at once under reduced
 * motion). Sits on the end side, clear of the upload progress pill.
 */
const { t } = useI18n();

const visible = useScrolled(() => window.innerHeight);

function toTop(): void {
    window.scrollTo({
        top: 0,
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
    });
}
</script>

<template>
    <Transition
        enter-active-class="transition-[opacity,translate] duration-200 ease-out"
        enter-from-class="translate-y-2 opacity-0"
        leave-active-class="transition-[opacity,translate] duration-150 ease-in"
        leave-to-class="translate-y-2 opacity-0"
    >
        <button
            v-if="visible"
            type="button"
            data-slot="back-to-top"
            :aria-label="t('ui.backToTop')"
            :title="t('ui.backToTop')"
            class="fixed end-4 bottom-20 z-30 flex size-10 cursor-pointer items-center justify-center rounded-full border border-border bg-card text-foreground shadow-md outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring/40 sm:end-6"
            @click="toTop"
        >
            <ArrowUp class="size-4" />
        </button>
    </Transition>
</template>
