<script setup lang="ts">
import { Lock } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import AuthField from '@/components/auth/AuthField.vue';
import { useKeyboardHints } from '@/composables/useKeyboardHints';
import { passwordStrength } from '@/lib/authInput';

/**
 * Password field: reveal toggle that keeps the caret / selection and exposes
 * its state (aria-pressed + a label that flips), Caps Lock and "Arabic
 * characters typed" warnings, and an optional advisory strength meter.
 *
 * The whole control is dir="ltr" — the value, the leading icon and the
 * reveal button all follow the Latin text — so the toggle can never sit on
 * top of the first characters in an Arabic page.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        name: string;
        label: string;
        showLabel: string;
        hideLabel: string;
        required?: boolean;
        error?: string;
        success?: string;
        valid?: boolean;
        autocomplete?: string;
        placeholder?: string;
        strength?: boolean;
        icon?: Component;
        leadIcon?: Component;
    }>(),
    {
        required: false,
        error: '',
        success: '',
        valid: false,
        autocomplete: 'current-password',
        placeholder: undefined,
        strength: false,
        icon: undefined,
        leadIcon: undefined,
    },
);

const emit = defineEmits<{
    input: [value: string];
    blur: [];
}>();

const { t } = useI18n();
const hints = useKeyboardHints();
const el = ref<HTMLInputElement | null>(null);
const shown = ref(false);
const score = ref(0);
const filled = ref(false);

const strengthId = computed(() => `${props.id}-strength`);

const warning = computed(() => {
    if (hints.hint.value === 'arabic') {
        return t('authUi.err.pwArabic');
    }

    return hints.hint.value === 'caps' ? t('authUi.caps') : '';
});

const BAR_COLORS = [
    'var(--danger)',
    'var(--warning)',
    'var(--warning)',
    'var(--accent)',
    'var(--accent-text)',
];

function onInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    hints.onValue(value);
    score.value = passwordStrength(value);
    filled.value = value !== '';
    emit('input', value);
}

async function toggle(): Promise<void> {
    const input = el.value;

    if (!input) {
        return;
    }

    const { selectionStart, selectionEnd } = input;

    shown.value = !shown.value;
    await nextTick();

    // Changing `type` resets the selection in some engines; put it back.
    try {
        input.setSelectionRange(selectionStart, selectionEnd);
    } catch {
        // not selectable in this state: harmless.
    }
}

// Inertia's `reset-on-success` resets the <form> natively, which fires no
// `input` event: bring the meter and the reveal state back to empty.
function onFormReset(): void {
    shown.value = false;
    score.value = 0;
    filled.value = false;
    hints.onValue('');
}

onMounted(() => el.value?.form?.addEventListener('reset', onFormReset));
onBeforeUnmount(() =>
    el.value?.form?.removeEventListener('reset', onFormReset),
);

defineExpose({ focus: () => el.value?.focus() });
</script>

<template>
    <AuthField
        :id="id"
        :label="label"
        :required="required"
        :error="error"
        :warning="warning"
        :success="success"
        :valid="valid"
        dir="ltr"
        :icon="icon"
        :describedby="strength ? strengthId : ''"
    >
        <template #label-extra>
            <slot name="label-extra" />
        </template>
        <template #lead>
            <component :is="leadIcon ?? Lock" />
        </template>
        <template #default="{ inputProps }">
            <input
                v-bind="inputProps"
                ref="el"
                :name="name"
                :type="shown ? 'text' : 'password'"
                dir="ltr"
                :required="required"
                :autocomplete="autocomplete"
                :placeholder="placeholder"
                autocapitalize="off"
                spellcheck="false"
                class="auth-input"
                @input="onInput"
                @keydown="hints.onKey"
                @keyup="hints.onKey"
                @blur="
                    hints.reset();
                    emit('blur');
                "
            />
        </template>
        <template #trailing>
            <button
                type="button"
                class="auth-reveal"
                :aria-controls="id"
                :aria-pressed="shown"
                :aria-label="shown ? hideLabel : showLabel"
                @mousedown.prevent
                @click="toggle"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path
                        d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"
                    />
                    <circle cx="12" cy="12" r="3" />
                    <path class="slash" d="m3.5 3.5 17 17" />
                </svg>
            </button>
        </template>
        <template v-if="strength" #below>
            <div :id="strengthId" class="auth-strength">
                <span class="auth-bars" aria-hidden="true">
                    <span
                        v-for="i in 4"
                        :key="i"
                        :class="{ on: filled && i <= Math.max(1, score) }"
                        :style="{ '--bar': BAR_COLORS[score] }"
                    />
                </span>
                <span class="auth-strength-label">
                    <template v-if="filled">
                        <span class="sr-only">{{
                            t('authUi.strengthLabel')
                        }}</span>
                        {{ t(`authUi.strength${score}`) }}
                    </template>
                </span>
            </div>
        </template>
    </AuthField>
</template>
