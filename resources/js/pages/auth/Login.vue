<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Mail, User } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import AuthErrorSummary from '@/components/auth/AuthErrorSummary.vue';
import AuthLockoutNotice from '@/components/auth/AuthLockoutNotice.vue';
import AuthPasswordInput from '@/components/auth/AuthPasswordInput.vue';
import AuthSubmitButton from '@/components/auth/AuthSubmitButton.vue';
import AuthTextField from '@/components/auth/AuthTextField.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    emailRule,
    requiredText,
    useAuthForm,
} from '@/composables/useAuthForm';
import { useAuthStage } from '@/composables/useAuthStage';
import { useLockoutCountdown } from '@/composables/useLockoutCountdown';
import { canonicalLoginEmail } from '@/lib/lockoutCountdown';
import {
    classifyLogin429,
    formatWaitDuration,
    login429Message,
} from '@/lib/loginThrottle';
import type { Translate } from '@/lib/loginThrottle';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

/**
 * Presentation only. The form still posts `email`, `password` and `remember`
 * to Fortify through the same wayfinder action, and every redirect is the
 * server's. useAuthForm adds an earlier, localised message for empty
 * fields, shows the server's errors (including `auth.failed`, which Fortify
 * attaches to `email`) on the right field and in the alert, and refuses a
 * second submit while one is in flight.
 */
defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const { t } = useI18n();
const { setStageState } = useAuthStage();
const tr: Translate = (key, named, plural) =>
    plural === undefined ? t(key, named ?? {}) : t(key, named ?? {}, plural);

const auth = useAuthForm(
    [
        {
            name: 'email',
            id: 'login-email',
            label: () => t('auth.email'),
            rule: emailRule,
        },
        {
            name: 'password',
            id: 'login-password',
            label: () => t('auth.password'),
            rule: requiredText,
        },
    ],
    (key, params) => t(key, params ?? {}),
);

const lockout = useLockoutCountdown({
    email: () => auth.values.email ?? '',
    // The lockout message is no longer true once the wait is over.
    onExpire: () => auth.clearServerError('email'),
});

const lockoutDescription = computed(() =>
    t('authUi.err.lockedFor', {
        duration: formatWaitDuration(lockout.remainingSeconds.value, tr),
    }),
);

// The address a request was sent for, so a late response is attributed to it
// even if the field has changed meanwhile.
let pendingEmail: string | null = null;

onMounted(() => auth.syncFromDom());

/** Inertia `onBefore`: false cancels the visit (lockout or in-flight submit). */
function onBefore(): boolean {
    auth.syncFromDom();

    if (lockout.isLocked(auth.values.email ?? '')) {
        return false;
    }

    if (!auth.begin()) {
        return false;
    }

    pendingEmail = canonicalLoginEmail(auth.values.email ?? '');
    lockout.clearReady();

    return true;
}

function onFinish(): void {
    auth.finish();
    pendingEmail = null;
}

/**
 * A 429 from POST /login (busy, locked or throttled) is a plain response with
 * no Inertia payload, which Inertia would otherwise paint as the raw server
 * error page in a dialog. Show it as the form's own error instead, worded
 * from the response headers (see lib/loginThrottle.ts; the body is never
 * shown), and return false so the dialog is not shown. Any other HTTP error
 * keeps Inertia's default handling.
 *
 * Only a lockout starts a countdown. A late lockout response for an address
 * no longer in the field is remembered silently (no alert, no focus move).
 */
function onHttpException(response: {
    status: number;
    headers: Record<string, string>;
}): boolean | void {
    if (response.status !== 429) {
        return;
    }

    setStageState('idle');

    const current = canonicalLoginEmail(auth.values.email ?? '');
    const submitted = pendingEmail ?? current;
    const result = classifyLogin429(response.headers);

    if (result.kind === 'locked') {
        lockout.record(submitted, result.seconds);
    }

    if (result.kind !== 'locked' || submitted === current) {
        auth.setServerErrors({
            email: login429Message(response.headers, tr),
        });
    }

    return false;
}
</script>

<template>
    <Head :title="t('auth.loginTitle')" />

    <div class="auth-stagger">
        <div>
            <span class="auth-eyebrow">
                <User class="size-3.5" aria-hidden="true" />
                {{ t('authUi.eyebrowLogin') }}
            </span>
        </div>
        <h1 class="auth-title" data-auth-title tabindex="-1">
            {{ t('auth.loginTitle') }}
        </h1>
        <p class="auth-sub">{{ t('auth.loginDesc') }}</p>
    </div>

    <!-- Status notice (e.g. after a password reset) -->
    <p v-if="status" role="status" class="auth-notice mb-5">{{ status }}</p>

    <Form
        v-bind="store.form()"
        novalidate
        :reset-on-success="['password']"
        class="auth-form auth-stagger"
        v-slot="{ processing, wasSuccessful }"
        @before="onBefore"
        @start="setStageState('busy')"
        @success="setStageState('stamped')"
        @error="
            (errors) => {
                setStageState('idle');
                auth.setServerErrors(errors);
            }
        "
        @http-exception="onHttpException"
        @finish="onFinish"
    >
        <AuthErrorSummary :message="auth.firstError.value" />

        <!-- Passkey flow: the existing component, untouched. -->
        <div class="auth-passkey">
            <PasskeyVerify
                :label="t('auth.passkeyPrompt')"
                :loading-label="t('auth.authenticating')"
                :separator="t('auth.orEmail')"
            />
        </div>

        <AuthTextField
            id="login-email"
            name="email"
            type="email"
            dir="ltr"
            :label="t('auth.email')"
            :lead-icon="Mail"
            autocomplete="username"
            inputmode="email"
            :placeholder="t('auth.emailPlaceholder')"
            keyboard-hint="email"
            required
            autofocus
            v-bind="auth.bind('email')"
        />

        <AuthPasswordInput
            id="login-password"
            name="password"
            :label="t('auth.password')"
            :show-label="t('authUi.showPw')"
            :hide-label="t('authUi.hidePw')"
            autocomplete="current-password"
            :placeholder="t('auth.password')"
            required
            v-bind="auth.bind('password')"
        >
            <template v-if="canResetPassword" #label-extra>
                <Link :href="request()" class="auth-link">
                    {{ t('auth.forgotPassword') }}
                </Link>
            </template>
        </AuthPasswordInput>

        <label class="auth-check" for="remember">
            <Checkbox id="remember" name="remember" class="size-5" />
            <span>{{ t('auth.rememberMe') }}</span>
        </label>

        <AuthLockoutNotice
            v-if="lockout.active.value || lockout.ready.value"
            :active="lockout.active.value"
            :ready="lockout.ready.value"
            :clock-text="lockout.clockText.value"
            :description="lockoutDescription"
            :sound-supported="lockout.soundSupported"
            :sound-enabled="lockout.soundEnabled.value"
            :sound-blocked="lockout.soundBlocked.value"
            @update:sound="lockout.setSound"
        />

        <!-- Always in the DOM so assistive tech registers it before the text appears. -->
        <div
            role="status"
            aria-live="polite"
            aria-atomic="true"
            data-test="lockout-status"
            class="empty:hidden"
        >
            <p v-if="lockout.ready.value" class="auth-notice">
                {{ t('authUi.lockout.ready') }}
            </p>
        </div>

        <AuthSubmitButton
            :blocked="lockout.active.value"
            :aria-describedby="
                lockout.active.value ? 'lockout-desc' : undefined
            "
            :processing="processing"
            :success="wasSuccessful"
            :label="t('auth.signInBtn')"
            :busy-label="t('authUi.verifying')"
            :done-label="t('authUi.verified')"
            data-test="login-button"
        />
    </Form>

    <p class="auth-foot">
        <span>{{ t('auth.noAccount') }}</span>
        &nbsp;
        <Link :href="register()" class="auth-link">
            {{ t('auth.createAccount') }}
        </Link>
    </p>
</template>
