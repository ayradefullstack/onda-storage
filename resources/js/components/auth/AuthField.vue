<script setup lang="ts">
import { Check, CircleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import AuthMessage from '@/components/auth/AuthMessage.vue';
import type { MessageKind } from '@/components/auth/AuthMessage.vue';

/**
 * One form field: label, required marker, hint, control chrome, valid / error
 * icons and a message region wired with aria-invalid / aria-describedby.
 *
 * The control is a slot; it receives `inputProps` (id, aria-invalid,
 * aria-describedby) to spread on the real element. `dir` decides which way
 * the WHOLE control runs: credentials, phone and Latin names are `ltr` (icon
 * beside where the value starts, reveal / state icons on the far side) even
 * on an Arabic page; Arabic names are `rtl`.
 *
 * `bare` drops the control chrome (used around AppCombobox, which draws its
 * own box) but keeps the label, message and aria wiring.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        label: string;
        required?: boolean;
        /** Server or client error; wins over `warning`. */
        error?: string;
        /** Advisory, non-blocking (Caps Lock, wrong keyboard language). */
        warning?: string;
        /** Positive confirmation, e.g. "passwords match". */
        success?: string;
        hint?: string;
        valid?: boolean;
        dir?: 'ltr' | 'rtl' | 'auto';
        icon?: Component;
        bare?: boolean;
        /** Extra ids for aria-describedby (e.g. the strength meter). */
        describedby?: string;
        disabled?: boolean;
    }>(),
    {
        required: false,
        error: '',
        warning: '',
        success: '',
        hint: '',
        valid: false,
        dir: undefined,
        icon: undefined,
        bare: false,
        describedby: '',
        disabled: false,
    },
);

const msgId = computed(() => `${props.id}-msg`);
const hintId = computed(() => `${props.id}-hint`);

const state = computed<'error' | 'valid' | null>(() =>
    props.error ? 'error' : props.valid ? 'valid' : null,
);

const message = computed<{ text: string; kind: MessageKind } | null>(() => {
    if (props.error) {
        return { text: props.error, kind: 'error' };
    }

    if (props.warning) {
        return { text: props.warning, kind: 'warn' };
    }

    if (props.success) {
        return { text: props.success, kind: 'ok' };
    }

    return null;
});

const inputProps = computed(() => ({
    id: props.id,
    'aria-invalid': props.error ? ('true' as const) : undefined,
    'aria-describedby':
        [props.hint ? hintId.value : '', props.describedby, msgId.value]
            .filter(Boolean)
            .join(' ') || undefined,
}));

// A single 420 ms shake each time a field becomes invalid.
const shake = ref(false);

watch(
    () => props.error,
    (next, prev) => {
        if (next && !prev) {
            shake.value = true;
            setTimeout(() => (shake.value = false), 450);
        }
    },
);
</script>

<template>
    <div
        class="auth-field"
        :data-state="state"
        :data-shake="shake ? 'true' : undefined"
    >
        <div class="auth-field-head">
            <label :for="id" class="auth-label">
                <component :is="icon" v-if="icon" aria-hidden="true" />
                <span>{{ label }}</span>
                <span v-if="required" class="auth-req" aria-hidden="true"
                    >*</span
                >
            </label>
            <slot name="label-extra" />
        </div>

        <div
            v-if="!bare"
            class="auth-control"
            :dir="dir"
            :class="{ 'opacity-60': disabled }"
        >
            <span v-if="$slots.lead" class="auth-lead" aria-hidden="true">
                <slot name="lead" />
            </span>
            <slot :input-props="inputProps" />
            <span class="auth-state" aria-hidden="true">
                <Check class="i-ok" />
                <CircleAlert class="i-err" />
            </span>
            <slot name="trailing" />
        </div>
        <slot v-else :input-props="inputProps" />

        <p v-if="hint" :id="hintId" class="sr-only">{{ hint }}</p>
        <slot name="below" />

        <!-- Always in the DOM so aria-describedby never dangles. -->
        <div :id="msgId" class="auth-msg" :data-kind="message?.kind">
            <AuthMessage
                v-if="message"
                :message="message.text"
                :kind="message.kind"
            />
        </div>
    </div>
</template>
