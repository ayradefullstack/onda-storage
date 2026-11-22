<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Checkbox } from '@/components/ui/checkbox';

/**
 * Presentational lockout panel for the login form. The ticking clock is
 * aria-hidden on purpose: a live region would speak every second. Assistive
 * technology gets the static description (referenced by the submit button)
 * and, once the wait is over, the page's separate status message.
 */
const props = defineProps<{
    active: boolean;
    /** The wait is over: the timer goes, the sound control stays (see below). */
    ready?: boolean;
    clockText: string;
    description: string;
    soundSupported: boolean;
    soundEnabled: boolean;
    soundBlocked: boolean;
}>();

const emit = defineEmits<{ 'update:sound': [on: boolean] }>();

const { t } = useI18n();

const showBlocked = computed(() => props.soundEnabled && props.soundBlocked);
const soundDescribedBy = computed(() =>
    showBlocked.value
        ? 'lockout-sound-hint lockout-sound-blocked-hint'
        : 'lockout-sound-hint',
);
</script>

<template>
    <!--
        Stays mounted after the wait (`ready`) until the next submit or an email
        change, so a keyboard user whose focus is on the sound toggle at expiry
        does not lose it. Only the timer and its description go away.
    -->
    <div
        v-if="active || (ready && soundSupported)"
        class="auth-notice flex flex-col gap-3"
        data-test="lockout-notice"
    >
        <div v-if="active" class="flex items-center justify-between gap-3">
            <span aria-hidden="true">{{ t('authUi.lockout.remaining') }}</span>
            <span
                dir="ltr"
                aria-hidden="true"
                class="font-semibold tabular-nums [unicode-bidi:isolate]"
                data-test="lockout-timer"
            >
                {{ clockText }}
            </span>
        </div>

        <span
            v-if="active"
            id="lockout-desc"
            class="sr-only"
            data-test="lockout-description"
        >
            {{ description }}
        </span>

        <div v-if="soundSupported" class="flex flex-col gap-1">
            <label class="auth-check" for="lockout-sound">
                <Checkbox
                    id="lockout-sound"
                    class="size-5"
                    data-test="lockout-sound"
                    :model-value="soundEnabled"
                    :aria-describedby="soundDescribedBy"
                    @update:model-value="emit('update:sound', $event === true)"
                />
                <span>{{ t('authUi.lockout.sound') }}</span>
            </label>
            <small id="lockout-sound-hint" class="text-xs opacity-80">
                {{ t('authUi.lockout.soundHint') }}
            </small>
            <p
                v-if="showBlocked"
                id="lockout-sound-blocked-hint"
                class="text-xs"
                data-test="lockout-sound-blocked"
            >
                {{ t('authUi.lockout.soundBlocked') }}
            </p>
        </div>
    </div>
</template>
