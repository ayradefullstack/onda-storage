<script setup lang="ts">
import { Check } from '@lucide/vue';

/**
 * A numbered <fieldset> of the Register form. `complete` turns the number into
 * a check and reveals the "complete" label — which is `visibility: hidden`
 * until then, so a screen reader never hears "complete" about a section that
 * still has empty fields.
 */
defineProps<{
    number: number;
    title: string;
    doneLabel: string;
    complete: boolean;
}>();
</script>

<template>
    <fieldset class="auth-section" :data-complete="complete ? 'true' : 'false'">
        <legend class="auth-legend">
            <span class="auth-sec-num" aria-hidden="true">
                <b class="font-bold transition-opacity">{{ number }}</b>
                <Check />
            </span>
            <span>{{ title }}</span>
            <span class="auth-sec-state">{{ doneLabel }}</span>
        </legend>
        <slot />
    </fieldset>
</template>
