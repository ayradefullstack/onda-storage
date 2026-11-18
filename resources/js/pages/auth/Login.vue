<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Mail, User } from '@lucide/vue';
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import AuthErrorSummary from '@/components/auth/AuthErrorSummary.vue';
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

onMounted(() => auth.syncFromDom());
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
        @before="auth.begin"
        @start="setStageState('busy')"
        @success="setStageState('stamped')"
        @error="
            (errors) => {
                setStageState('idle');
                auth.setServerErrors(errors);
            }
        "
        @finish="auth.finish"
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
            placeholder="exemple@domaine.com"
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
            placeholder="Mot de passe"
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

        <AuthSubmitButton
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
