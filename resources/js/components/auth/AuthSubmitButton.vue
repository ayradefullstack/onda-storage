<script setup lang="ts">
import { ArrowRight, Check } from '@lucide/vue';
import { computed } from 'vue';

/**
 * Submit button with three states stacked in ONE grid cell (idle / loading /
 * success), so the width never changes. Bound to the form's `processing` /
 * `wasSuccessful`. While busy it is `aria-disabled` rather than `disabled`:
 * keyboard focus is kept, and the click is swallowed so a second submit can't
 * start (the page also refuses one in Inertia's `onBefore`).
 */
const props = defineProps<{
    processing?: boolean;
    success?: boolean;
    label: string;
    busyLabel: string;
    doneLabel: string;
}>();

const state = computed(() =>
    props.processing ? 'loading' : props.success ? 'success' : 'idle',
);

function guard(event: MouseEvent): void {
    if (props.processing) {
        event.preventDefault();
    }
}
</script>

<template>
    <button
        type="submit"
        class="auth-btn"
        :data-state="state"
        :aria-disabled="processing ? 'true' : undefined"
        :aria-busy="processing ? 'true' : undefined"
        @click="guard"
    >
        <span class="auth-btn-labels">
            <span class="l-idle" :aria-hidden="state !== 'idle'">
                <span>{{ label }}</span>
                <ArrowRight
                    class="size-4.5 rtl:-scale-x-100"
                    aria-hidden="true"
                />
            </span>
            <span class="l-busy" :aria-hidden="state !== 'loading'">
                <span class="auth-spinner" aria-hidden="true" />
                <span>{{ busyLabel }}</span>
            </span>
            <span class="l-done" :aria-hidden="state !== 'success'">
                <Check class="tick size-4.5" aria-hidden="true" />
                <span>{{ doneLabel }}</span>
            </span>
        </span>
    </button>
</template>
