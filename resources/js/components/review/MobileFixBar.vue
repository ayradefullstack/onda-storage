<script setup lang="ts">
import { CircleX } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

/**
 * Below `sm`: a sticky bottom bar "{n} fichier(s) à corriger" that jumps to
 * the first thing to fix. Hidden when there is nothing to fix and on larger
 * screens (the "À corriger" panel is in view there). Clears the device's
 * safe area; no animation, so nothing to reduce.
 */
defineProps<{ count: number }>();

const emit = defineEmits<{ go: [] }>();

const { t } = useI18n();
</script>

<template>
    <!-- Teleported: a transformed ancestor (the page transition) would
         otherwise make `fixed` scroll with the content. -->
    <Teleport to="body">
        <div
            v-if="count > 0"
            class="fixed inset-x-0 bottom-0 z-40 border-t border-destructive/30 bg-background/95 px-4 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] shadow-lg backdrop-blur sm:hidden"
            data-testid="mobile-fix-bar"
        >
            <button
                type="button"
                class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-lg bg-destructive px-4 py-2.5 text-sm font-semibold text-white outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                @click="emit('go')"
            >
                <CircleX class="size-4" aria-hidden="true" />
                {{ t('oeuvres.review.toFixBar', { count }, count) }}
            </button>
        </div>
    </Teleport>
</template>
