<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import AuthField from '@/components/auth/AuthField.vue';
import { useKeyboardHints } from '@/composables/useKeyboardHints';

/**
 * A plain text / email input inside an AuthField. Uncontrolled on purpose:
 * Inertia's <Form> reads the value from the DOM by `name`, so nothing here
 * can change what is posted. `input` / `blur` events only feed the
 * presentation layer (useAuthForm).
 */
const props = withDefaults(
    defineProps<{
        id: string;
        name: string;
        label: string;
        type?: string;
        dir?: 'ltr' | 'rtl' | 'auto';
        required?: boolean;
        error?: string;
        valid?: boolean;
        hint?: string;
        tag?: string;
        /** Icon next to the label (Register) … */
        icon?: Component;
        /** … or inside the control, where the value starts (Login). */
        leadIcon?: Component;
        autocomplete?: string;
        placeholder?: string;
        inputmode?: 'text' | 'email' | 'tel';
        autocapitalize?: string;
        lang?: string;
        autofocus?: boolean;
        /** Warn (not block) when the Arabic keyboard layout is active. */
        keyboardHint?: 'email';
    }>(),
    {
        type: 'text',
        dir: 'ltr',
        required: false,
        error: '',
        valid: false,
        hint: '',
        tag: '',
        icon: undefined,
        leadIcon: undefined,
        autocomplete: undefined,
        placeholder: undefined,
        inputmode: undefined,
        autocapitalize: 'off',
        lang: undefined,
        autofocus: false,
        keyboardHint: undefined,
    },
);

const emit = defineEmits<{
    input: [value: string];
    blur: [];
}>();

const { t } = useI18n();
const hints = useKeyboardHints();
const el = ref<HTMLInputElement | null>(null);

const warning = computed(() =>
    props.keyboardHint === 'email' && hints.arabic.value
        ? t('authUi.err.emailArabic')
        : '',
);

function onInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    hints.onValue(value);
    emit('input', value);
}

defineExpose({ focus: () => el.value?.focus() });
</script>

<template>
    <AuthField
        :id="id"
        :label="label"
        :required="required"
        :error="error"
        :warning="warning"
        :valid="valid"
        :hint="hint"
        :dir="dir"
        :icon="icon"
    >
        <template v-if="tag" #label-extra>
            <span class="auth-tag">{{ tag }}</span>
        </template>
        <template v-if="leadIcon" #lead>
            <component :is="leadIcon" />
        </template>
        <template #default="{ inputProps }">
            <input
                v-bind="inputProps"
                ref="el"
                :name="name"
                :type="type"
                :dir="dir"
                :lang="lang"
                :required="required"
                :autocomplete="autocomplete"
                :autocapitalize="autocapitalize"
                :inputmode="inputmode"
                :placeholder="placeholder"
                :autofocus="autofocus"
                spellcheck="false"
                class="auth-input"
                @input="onInput"
                @blur="emit('blur')"
            />
        </template>
    </AuthField>
</template>
