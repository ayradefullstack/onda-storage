<script setup lang="ts">
import { ref } from 'vue';
import type { Component } from 'vue';
import AuthField from '@/components/auth/AuthField.vue';
import { groupPhone, sanitizePhone } from '@/lib/authInput';

/**
 * Mobile number: a fixed dial-code prefix (display only — it is not posted,
 * exactly as before), Arabic-Indic digits converted to 0-9 as they are typed
 * with the caret kept in place, and digit grouping once the field is left.
 * The control is dir="ltr" so the prefix and the number read as Latin text.
 */
const props = withDefaults(
    defineProps<{
        id: string;
        name: string;
        label: string;
        dialCode: string;
        iso?: string;
        flagUrl?: string;
        maxDigits?: number;
        /** Group `0512345678` as `05 12 34 56 78` on blur (national format). */
        groupOnBlur?: boolean;
        required?: boolean;
        error?: string;
        valid?: boolean;
        placeholder?: string;
        hint?: string;
        icon?: Component;
    }>(),
    {
        iso: '',
        flagUrl: undefined,
        maxDigits: 15,
        groupOnBlur: false,
        required: false,
        error: '',
        valid: false,
        placeholder: undefined,
        hint: '',
        icon: undefined,
    },
);

const emit = defineEmits<{
    input: [value: string];
    blur: [];
}>();

const el = ref<HTMLInputElement | null>(null);

function onInput(event: Event): void {
    const input = event.target as HTMLInputElement;
    const edit = sanitizePhone(
        input.value,
        input.selectionStart ?? input.value.length,
        props.maxDigits,
    );

    if (edit.value !== input.value) {
        input.value = edit.value;
        input.setSelectionRange(edit.caret, edit.caret);
    }

    emit('input', input.value);
}

function onBlur(): void {
    const input = el.value;

    if (input && props.groupOnBlur) {
        const grouped = groupPhone(input.value);

        if (grouped !== input.value) {
            input.value = grouped;
            emit('input', grouped);
        }
    }

    emit('blur');
}

defineExpose({ focus: () => el.value?.focus() });
</script>

<template>
    <AuthField
        :id="id"
        :label="label"
        :required="required"
        :error="error"
        :valid="valid"
        :hint="hint"
        :icon="icon"
        dir="ltr"
    >
        <template #default="{ inputProps }">
            <span class="auth-prefix" aria-hidden="true">
                <img
                    v-if="flagUrl"
                    :src="flagUrl"
                    alt=""
                    class="size-4 shrink-0 rounded-xs object-cover"
                    loading="lazy"
                />
                <span v-if="iso" class="auth-iso">{{ iso }}</span>
                <span>{{ dialCode }}</span>
            </span>
            <input
                v-bind="inputProps"
                ref="el"
                :name="name"
                type="tel"
                dir="ltr"
                inputmode="tel"
                autocomplete="tel-national"
                :required="required"
                :placeholder="placeholder"
                class="auth-input"
                @input="onInput"
                @blur="onBlur"
            />
        </template>
    </AuthField>
</template>
